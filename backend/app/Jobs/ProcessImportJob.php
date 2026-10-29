<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Import;
use App\Models\ImportChunk;
use App\Models\ImportRowError;
use App\Services\CustomerRowValidator;
use App\Services\ExcelParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $importId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        ExcelParserService $parserService,
        CustomerRowValidator $rowValidator
    ): void {
        $import = Import::find($this->importId);
        if (! $import) {
            Log::warning("ProcessImportJob: Import [{$this->importId}] not found.");

            return;
        }

        // If import is already in a terminal state, do not re-process
        if (in_array($import->status, [Import::STATUS_COMPLETED, Import::STATUS_COMPLETED_WITH_ERRORS], true)) {
            Log::info("ProcessImportJob: Import [{$this->importId}] is already completed.");

            return;
        }

        $importDisk = config('excel_import.disk', 'local');
        $fullPath = Storage::disk($importDisk)->path($import->file_path);

        if (! file_exists($fullPath) || ! is_readable($fullPath)) {
            $import->markFailed('Stored spreadsheet file not found or not readable on disk.');

            return;
        }

        // Attempt to detect total rows as early as practical if unknown
        $detectedTotal = $parserService->detectTotalRows($fullPath);

        // Mark import as Processing
        $import->update([
            'status' => Import::STATUS_PROCESSING,
            'started_at' => $import->started_at ?? now(),
            'total_rows' => ($import->total_rows > 0) ? $import->total_rows : ($detectedTotal ?? 0),
            'failure_reason' => null,
        ]);

        $chunkSize = (int) config('excel_import.chunk_size', 1000);
        if ($chunkSize < 1) {
            $chunkSize = 1000;
        }

        try {
            $columnMap = $parserService->validateHeaders($fullPath);
            $reader = $parserService->createReader();
            $reader->open($fullPath);

            $excelRowNumber = 0;
            $currentChunkNumber = 1;
            $chunkBuffer = [];
            $seenFileCustomerIds = []; // customer_id => first_seen_row

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $excelRowNumber++;

                    // Row 1 is the header row
                    if ($excelRowNumber === 1) {
                        continue;
                    }

                    $chunkBuffer[] = [
                        'row_number' => $excelRowNumber,
                        'values' => $row->toArray(),
                    ];

                    if (count($chunkBuffer) >= $chunkSize) {
                        $this->processChunk(
                            $import,
                            $currentChunkNumber,
                            $chunkBuffer,
                            $columnMap,
                            $rowValidator,
                            $seenFileCustomerIds
                        );
                        $chunkBuffer = [];
                        $currentChunkNumber++;
                    }
                }
                // Only process the first sheet
                break;
            }

            // Process any remaining rows in the final chunk
            if (! empty($chunkBuffer)) {
                $this->processChunk(
                    $import,
                    $currentChunkNumber,
                    $chunkBuffer,
                    $columnMap,
                    $rowValidator,
                    $seenFileCustomerIds
                );
            }

            $reader->close();

            // Refresh import and calculate terminal state
            $import->refresh();
            $completedAt = now();
            $duration = abs(round($completedAt->diffInRealSeconds($import->started_at ?? $import->created_at), 2));

            $terminalStatus = ($import->rejected_rows > 0)
                ? Import::STATUS_COMPLETED_WITH_ERRORS
                : Import::STATUS_COMPLETED;

            $import->update([
                'status' => $terminalStatus,
                'total_rows' => $import->processed_rows,
                'completed_at' => $completedAt,
                'duration_seconds' => $duration,
            ]);

            Log::info("ProcessImportJob: Import [{$import->id}] finished. Status: {$terminalStatus}, Processed: {$import->processed_rows}, Saved: {$import->saved_rows}, Rejected: {$import->rejected_rows}");

        } catch (Throwable $e) {
            Log::error("ProcessImportJob error on Import [{$import->id}]: ".$e->getMessage());

            // If running synchronously outside a queue, or if maximum queue attempts are exhausted,
            // mark the import failed immediately. Otherwise, leave status as Processing for retry.
            if ($this->job === null || $this->attempts() >= $this->tries) {
                $this->markImportFailed($import, $e);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure after all queue attempts have been exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $import = Import::find($this->importId);
        if (! $import) {
            return;
        }

        $this->markImportFailed($import, $exception);

        Log::error("ProcessImportJob: Import [{$this->importId}] marked as Failed in failed() lifecycle hook.");
    }

    /**
     * Mark an import as failed with sanitized error details and accurate duration.
     */
    protected function markImportFailed(Import $import, ?Throwable $exception): void
    {
        $message = $exception ? $exception->getMessage() : 'Import processing failed after maximum attempts.';

        $import->refresh();
        $import->markFailed($this->sanitizeErrorMessage($message));
    }

    /**
     * Process an individual chunk idempotently inside a database transaction.
     *
     * @param  array<int, array{row_number: int, cells: array}>  $chunkBuffer
     * @param  array<string, int>  $columnMap
     * @param  array<string, int>  &$seenFileCustomerIds  Reference to all seen IDs in this file
     */
    protected function processChunk(
        Import $import,
        int $chunkNumber,
        array $chunkBuffer,
        array $columnMap,
        CustomerRowValidator $rowValidator,
        array &$seenFileCustomerIds
    ): void {
        $firstRow = $chunkBuffer[0]['row_number'];
        $lastRow = $chunkBuffer[count($chunkBuffer) - 1]['row_number'];
        $totalInChunk = count($chunkBuffer);

        // 1. Check if chunk was already completed in a prior run
        $existingChunk = ImportChunk::where('import_id', $import->id)
            ->where('chunk_number', $chunkNumber)
            ->first();

        if ($existingChunk && $existingChunk->status === ImportChunk::STATUS_COMPLETED) {
            // Already processed and committed; track seen IDs and skip to prevent double counting
            foreach ($chunkBuffer as $item) {
                $raw = $this->extractRowData($item['values'], $columnMap);
                $cid = isset($raw['customer_id']) ? trim((string) $raw['customer_id']) : '';
                if ($cid !== '' && ! isset($seenFileCustomerIds[$cid])) {
                    $seenFileCustomerIds[$cid] = $item['row_number'];
                }
            }

            return;
        }

        $attempts = ($existingChunk ? $existingChunk->attempts : 0) + 1;

        // 2. Claim chunk atomically
        $chunk = ImportChunk::updateOrCreate(
            ['import_id' => $import->id, 'chunk_number' => $chunkNumber],
            [
                'start_row' => $firstRow,
                'end_row' => $lastRow,
                'status' => ImportChunk::STATUS_PROCESSING,
                'total_rows' => $totalInChunk,
                'claimed_at' => now(),
                'attempts' => $attempts,
            ]
        );

        $validCandidates = []; // customer_id => ['row_number' => int, 'data' => array]
        $invalidErrors = [];   // Format / validation schema errors
        $duplicateErrors = []; // In-file, database, and race duplicate errors

        // 3. Row-level validation and in-file duplicate detection
        foreach ($chunkBuffer as $item) {
            $excelRow = $item['row_number'];
            $rawData = $this->extractRowData($item['values'], $columnMap);

            $validation = $rowValidator->validate($rawData);

            if (! $validation['valid']) {
                $invalidErrors[] = [
                    'import_id' => $import->id,
                    'excel_row' => $excelRow,
                    'customer_id' => $validation['customer_id'],
                    'rejection_reason' => $validation['error'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                continue;
            }

            $customerId = $validation['customer_id'];

            // In-file duplicate check
            if (isset($seenFileCustomerIds[$customerId])) {
                $firstRowSeen = $seenFileCustomerIds[$customerId];
                $duplicateErrors[] = [
                    'import_id' => $import->id,
                    'excel_row' => $excelRow,
                    'customer_id' => $customerId,
                    'rejection_reason' => "Duplicate Customer ID '{$customerId}' within file (first seen at row {$firstRowSeen}).",
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                continue;
            }

            // Register as seen in file and queue as valid candidate for DB check
            $seenFileCustomerIds[$customerId] = $excelRow;
            $validCandidates[$customerId] = [
                'excel_row' => $excelRow,
                'data' => $validation['data'],
            ];
        }

        // 4. In-database duplicate detection for valid candidates
        $customersToInsert = [];
        $rowNumberMap = []; // customer_id => physical Excel row number
        if (! empty($validCandidates)) {
            $candidateIds = array_keys($validCandidates);
            $existingDbIds = Customer::whereIn('customer_id', $candidateIds)
                ->pluck('customer_id')
                ->flip()
                ->all();

            foreach ($validCandidates as $cid => $candidate) {
                $rowNumberMap[$cid] = $candidate['excel_row'];

                if (isset($existingDbIds[$cid])) {
                    $duplicateErrors[] = [
                        'import_id' => $import->id,
                        'excel_row' => $candidate['excel_row'],
                        'customer_id' => $cid,
                        'rejection_reason' => "Customer ID '{$cid}' already exists in database.",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                } else {
                    $customersToInsert[] = array_merge($candidate['data'], [
                        'import_id' => $import->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // 5. Persist chunk changes, errors, and counters inside a single transaction
        DB::transaction(function () use (
            $import,
            $chunk,
            $totalInChunk,
            $customersToInsert,
            $invalidErrors,
            $duplicateErrors,
            $rowNumberMap
        ) {
            // Bulk insert valid customer records with race condition fallback
            if (! empty($customersToInsert)) {
                $this->insertCustomersSafely($customersToInsert, $import->id, $duplicateErrors, $rowNumberMap);
            }

            $rowErrors = array_merge($invalidErrors, $duplicateErrors);

            // Bulk insert row errors
            if (! empty($rowErrors)) {
                // Insert in batches of 500 to stay well within SQL parameter limits
                foreach (array_chunk($rowErrors, 500) as $errorBatch) {
                    ImportRowError::insert($errorBatch);
                }
            }

            $savedCount = count($customersToInsert);
            $invalidCount = count($invalidErrors);
            $duplicateCount = count($duplicateErrors);
            $validCount = $savedCount;
            $validatedCount = $totalInChunk;
            $rejectedCount = $invalidCount + $duplicateCount;
            $processedCount = $savedCount + $rejectedCount;

            // Update chunk status to completed
            $chunk->update([
                'status' => ImportChunk::STATUS_COMPLETED,
                'total_rows' => $totalInChunk,
                'processed_rows' => $processedCount,
                'validated_rows' => $validatedCount,
                'valid_rows' => $validCount,
                'invalid_rows' => $invalidCount,
                'duplicate_rows' => $duplicateCount,
                'saved_rows' => $savedCount,
                'rejected_rows' => $rejectedCount,
                'completed_at' => now(),
            ]);

            // Increment parent import counters atomically
            $import->increment('processed_rows', $processedCount);
            $import->increment('validated_rows', $validatedCount);
            $import->increment('valid_rows', $validCount);
            $import->increment('invalid_rows', $invalidCount);
            $import->increment('duplicate_rows', $duplicateCount);
            $import->increment('saved_rows', $savedCount);
            $import->increment('rejected_rows', $rejectedCount);
        });
    }

    /**
     * Safely insert customers with duplicate-key race fallback.
     *
     * @param  array<int, array>  $customers
     * @param  array<int, array>  $duplicateErrors
     * @param  array<string, int>  $rowNumberMap  Map of customer_id => physical Excel row number
     */
    protected function insertCustomersSafely(
        array &$customers,
        int $importId,
        array &$duplicateErrors,
        array $rowNumberMap = []
    ): void {
        $savedCustomers = [];

        // Process in bounded sub-batches of 500 to adhere to SQL parameter limits
        foreach (array_chunk($customers, 500) as $batchIndex => $batch) {
            try {
                Customer::insert($batch);
                $savedCustomers = array_merge($savedCustomers, $batch);
            } catch (QueryException $e) {
                // Check for duplicate key violation (MySQL error 1062)
                if ($e->errorInfo[1] === 1062 || str_contains($e->getMessage(), 'Duplicate entry')) {
                    Log::warning("ProcessImportJob: Concurrent duplicate key race detected on import [{$importId}], sub-batch [{$batchIndex}]. Falling back to row-by-row insertion for conflicting sub-batch.");

                    // Fall back to row-by-row insertion ONLY for records in this failed sub-batch
                    foreach ($batch as $record) {
                        try {
                            Customer::create($record);
                            $savedCustomers[] = $record;
                        } catch (QueryException $rowEx) {
                            if ($rowEx->errorInfo[1] === 1062 || str_contains($rowEx->getMessage(), 'Duplicate entry')) {
                                $cid = $record['customer_id'];
                                $excelRow = $rowNumberMap[$cid] ?? 0;

                                $duplicateErrors[] = [
                                    'import_id' => $importId,
                                    'excel_row' => $excelRow,
                                    'customer_id' => $cid,
                                    'rejection_reason' => "Customer ID '{$cid}' was inserted concurrently in another process.",
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                            } else {
                                throw $rowEx;
                            }
                        }
                    }
                } else {
                    throw $e;
                }
            }
        }

        // Update the reference array so callers reflect the actual successfully inserted customers
        $customers = $savedCustomers;
    }

    /**
     * Extract associative array using column index map.
     *
     * @param  array<string, int>  $columnMap
     * @return array<string, mixed>
     */
    protected function extractRowData(array $values, array $columnMap): array
    {
        $data = [];
        foreach ($columnMap as $field => $index) {
            $data[$field] = $values[$index] ?? null;
        }

        return $data;
    }

    /**
     * Remove sensitive details (e.g. database credentials or internal server paths) from error messages.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        // Strip SQL queries, passwords, and server root paths
        $sanitized = preg_replace('/(password|pwd|user|host)=\S+/i', '$1=[redacted]', $message);
        $sanitized = preg_replace('/\/media\/[^\s:]+/', '[storage-path]', $sanitized);
        $sanitized = preg_replace('/\/home\/[^\s:]+/', '[server-path]', $sanitized);

        return substr((string) $sanitized, 0, 500);
    }
}
