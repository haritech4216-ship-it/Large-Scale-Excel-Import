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
            $import->update([
                'status' => Import::STATUS_FAILED,
                'failure_reason' => 'Stored spreadsheet file not found or not readable on disk.',
                'completed_at' => now(),
            ]);

            return;
        }

        // Mark import as Processing
        $import->update([
            'status' => Import::STATUS_PROCESSING,
            'started_at' => $import->started_at ?? now(),
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
            $duration = round($completedAt->diffInRealSeconds($import->started_at ?? $import->created_at), 2);

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

            $import->refresh();
            $import->update([
                'status' => Import::STATUS_FAILED,
                'failure_reason' => $this->sanitizeErrorMessage($e->getMessage()),
                'completed_at' => now(),
            ]);

            throw $e;
        }
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
        $rowErrors = [];       // list of ['import_id', 'excel_row', 'customer_id', 'rejection_reason', 'created_at', 'updated_at']

        // 3. Row-level validation and in-file duplicate detection
        foreach ($chunkBuffer as $item) {
            $excelRow = $item['row_number'];
            $rawData = $this->extractRowData($item['values'], $columnMap);

            $validation = $rowValidator->validate($rawData);

            if (! $validation['valid']) {
                $rowErrors[] = [
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
                $rowErrors[] = [
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
        if (! empty($validCandidates)) {
            $candidateIds = array_keys($validCandidates);
            $existingDbIds = Customer::whereIn('customer_id', $candidateIds)
                ->pluck('customer_id')
                ->flip()
                ->all();

            foreach ($validCandidates as $cid => $candidate) {
                if (isset($existingDbIds[$cid])) {
                    $rowErrors[] = [
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

        $savedCount = count($customersToInsert);
        $rejectedCount = count($rowErrors);
        $processedCount = $savedCount + $rejectedCount;

        // 5. Persist chunk changes, errors, and counters inside a single transaction
        DB::transaction(function () use (
            $import,
            $chunk,
            $customersToInsert,
            $rowErrors,
            $processedCount

        ) {
            // Bulk insert valid customer records with race condition fallback
            if (! empty($customersToInsert)) {
                $this->insertCustomersSafely($customersToInsert, $import->id, $rowErrors);
            }

            // Bulk insert row errors
            if (! empty($rowErrors)) {
                // Insert in batches of 500 to stay well within SQL parameter limits
                foreach (array_chunk($rowErrors, 500) as $errorBatch) {
                    ImportRowError::insert($errorBatch);
                }
            }

            // Update chunk status to completed
            $chunk->update([
                'status' => ImportChunk::STATUS_COMPLETED,
                'processed_rows' => $processedCount,
                'saved_rows' => count($customersToInsert),
                'rejected_rows' => count($rowErrors),
                'completed_at' => now(),
            ]);

            // Increment parent import counters atomically
            $import->increment('processed_rows', $processedCount);
            $import->increment('saved_rows', count($customersToInsert));
            $import->increment('rejected_rows', count($rowErrors));
        });
    }

    /**
     * Safely insert customers with duplicate-key race fallback.
     *
     * @param  array<int, array>  $customers
     */
    protected function insertCustomersSafely(array &$customers, int $importId, array &$rowErrors): void
    {
        try {
            foreach (array_chunk($customers, 500) as $batch) {
                Customer::insert($batch);
            }
        } catch (QueryException $e) {
            // Check for duplicate key violation (MySQL error 1062)
            if ($e->errorInfo[1] === 1062 || str_contains($e->getMessage(), 'Duplicate entry')) {
                Log::warning("ProcessImportJob: Concurrent duplicate key race detected on import [{$importId}]. Falling back to row-by-row insertion.");

                $successful = [];
                foreach ($customers as $record) {
                    try {
                        Customer::create($record);
                        $successful[] = $record;
                    } catch (QueryException $rowEx) {
                        if ($rowEx->errorInfo[1] === 1062 || str_contains($rowEx->getMessage(), 'Duplicate entry')) {
                            $cid = $record['customer_id'];
                            $rowErrors[] = [
                                'import_id' => $importId,
                                'excel_row' => 0,
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
                $customers = $successful;
            } else {
                throw $e;
            }
        }
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
