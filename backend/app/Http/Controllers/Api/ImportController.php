<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadImportRequest;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Services\ExcelParserService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(
        protected ExcelParserService $parserService
    ) {}

    /**
     * Upload an .xlsx spreadsheet and queue import processing.
     *
     * POST /api/imports
     */
    public function store(UploadImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $originalFilename = $file->getClientOriginalName();
        $disk = config('excel_import.disk', 'local');
        $directory = config('excel_import.directory', 'imports');

        // Generate safe non-user-controlled filename
        $safeFilename = Str::uuid()->toString().'.xlsx';
        $storedPath = $file->storeAs($directory, $safeFilename, $disk);

        if (! $storedPath) {
            return response()->json([
                'message' => 'Failed to store uploaded file on the server.',
            ], 500);
        }

        $absolutePath = Storage::disk($disk)->path($storedPath);

        // Pre-validate that workbook can be parsed and has all required headers
        try {
            $this->parserService->validateHeaders($absolutePath);
        } catch (Exception $e) {
            // Remove uploaded file since it failed structural validation
            Storage::disk($disk)->delete($storedPath);

            return response()->json([
                'message' => 'Workbook structure validation failed.',
                'errors' => [
                    'file' => [$e->getMessage()],
                ],
            ], 422);
        }

        $detectedTotal = $this->parserService->detectTotalRows($absolutePath);

        // Create import record with Queued status
        $import = Import::create([
            'user_id' => $request->user()->id,
            'filename' => $originalFilename,
            'file_path' => $storedPath,
            'status' => Import::STATUS_QUEUED,
            'total_rows' => $detectedTotal ?? 0,
        ]);

        // Dispatch asynchronous queue job
        ProcessImportJob::dispatch($import->id);

        return response()->json([
            'id' => $import->id,
            'filename' => $import->filename,
            'status' => $import->status,
            'message' => 'File uploaded and import queued successfully.',
        ], 201);
    }

    /**
     * Get import details, counts, and status.
     *
     * GET /api/imports/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $import = Import::find($id);
        if (! $import) {
            return response()->json([
                'message' => "Import [{$id}] not found.",
            ], 404);
        }

        if ($import->user_id === null || (int) $request->user()->id !== (int) $import->user_id) {
            return response()->json([
                'message' => 'This action is unauthorized.',
            ], 403);
        }

        $progress = $this->calculateProgress($import);

        return response()->json([
            'id' => $import->id,
            'filename' => $import->filename,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'total_rows_known' => $progress['total_rows_known'],
            'processed_rows' => $import->processed_rows,
            'validated_rows' => $import->validated_rows,
            'valid_rows' => $import->valid_rows,
            'invalid_rows' => $import->invalid_rows,
            'duplicate_rows' => $import->duplicate_rows,
            'saved_rows' => $import->saved_rows,
            'rejected_rows' => $import->rejected_rows,
            'progress_percentage' => $progress['progress_percentage'],
            'stages' => $progress['stages'],
            'started_at' => $import->started_at?->toISOString(),
            'completed_at' => $import->completed_at?->toISOString(),
            'duration_seconds' => $import->duration_seconds,
            'failure_reason' => $import->failure_reason,
            'created_at' => $import->created_at->toISOString(),
            'updated_at' => $import->updated_at->toISOString(),
        ], 200);
    }

    /**
     * Compute progress counters, percentages, and three-stage lifecycle state for an import.
     *
     * @return array{total_rows_known: bool, progress_percentage: float, stages: array}
     */
    public function calculateProgress(Import $import): array
    {
        $isTerminal = in_array($import->status, [
            Import::STATUS_COMPLETED,
            Import::STATUS_COMPLETED_WITH_ERRORS,
            Import::STATUS_FAILED,
        ], true);

        $isTerminalSuccessful = $isTerminal && $import->status !== Import::STATUS_FAILED;

        // Total rows is known if total_rows > 0 or if the job completed and processed_rows is 0 (genuine 0-row file)
        $totalKnown = ($import->total_rows > 0) || ($isTerminalSuccessful && $import->processed_rows === 0);

        // Overall progress percentage
        $progressPercentage = 0.0;
        if ($isTerminalSuccessful) {
            $progressPercentage = 100.0;
        } elseif ($totalKnown && $import->total_rows > 0) {
            $progressPercentage = min(99.9, round(($import->processed_rows / $import->total_rows) * 100, 1));
        }

        // Stage 2: Validation progress
        $validationPercentage = 0.0;
        if ($isTerminalSuccessful) {
            $validationPercentage = 100.0;
        } elseif ($import->status === Import::STATUS_FAILED && $totalKnown && $import->total_rows > 0 && $import->validated_rows >= $import->total_rows) {
            $validationPercentage = 100.0;
        } elseif ($totalKnown && $import->total_rows > 0) {
            $validationPercentage = min(99.9, round(($import->validated_rows / $import->total_rows) * 100, 1));
        }

        // Stage 3: Database import progress
        // Stage 3 denominator represents total eligible rows.
        // If validation has completed across the entire file, total eligible rows is known ($import->valid_rows).
        // If validation is still in progress (more rows remain unread) or total rows is unknown,
        // total eligible rows is not yet known, so Stage 3 is indeterminate (never jumping prematurely to 99.9% or 100%).
        $validationCompleted = $isTerminalSuccessful || ($totalKnown && $import->total_rows > 0 && $import->validated_rows >= $import->total_rows);

        $eligibleRows = $import->valid_rows;
        $importPercentage = 0.0;
        $importIndeterminate = false;

        if ($isTerminalSuccessful) {
            if ($eligibleRows > 0 && $import->saved_rows >= $eligibleRows) {
                $importPercentage = 100.0;
            } elseif ($eligibleRows > 0) {
                $importPercentage = round(($import->saved_rows / $eligibleRows) * 100, 1);
            } else {
                $importPercentage = 0.0;
            }
        } elseif ($import->status === Import::STATUS_PROCESSING) {
            if ($validationCompleted) {
                if ($eligibleRows > 0) {
                    $importPercentage = min(99.9, round(($import->saved_rows / $eligibleRows) * 100, 1));
                } else {
                    $importPercentage = 0.0;
                }
            } else {
                $importIndeterminate = true;
            }
        } elseif ($import->status === Import::STATUS_FAILED) {
            if ($validationCompleted && $eligibleRows > 0 && $import->saved_rows > 0) {
                $importPercentage = min(99.9, round(($import->saved_rows / $eligibleRows) * 100, 1));
            } else {
                $importPercentage = 0.0;
            }
        }

        $stages = [
            'upload' => [
                'status' => 'completed',
                'percentage' => 100.0,
            ],
            'validation' => [
                'status' => match ($import->status) {
                    Import::STATUS_QUEUED => 'queued',
                    Import::STATUS_PROCESSING => 'processing',
                    Import::STATUS_FAILED => 'failed',
                    default => 'completed',
                },
                'checked_rows' => $import->validated_rows,
                'total_rows' => $import->total_rows,
                'valid_rows' => $import->valid_rows,
                'invalid_rows' => $import->invalid_rows,
                'duplicate_rows' => $import->duplicate_rows,
                'percentage' => $validationPercentage,
                'indeterminate' => ($import->status === Import::STATUS_PROCESSING && ! $totalKnown),
            ],
            'import' => [
                'status' => match ($import->status) {
                    Import::STATUS_QUEUED => 'queued',
                    Import::STATUS_PROCESSING => 'processing',
                    Import::STATUS_FAILED => 'failed',
                    default => 'completed',
                },
                'saved_rows' => $import->saved_rows,
                'eligible_rows' => $eligibleRows,
                'percentage' => $importPercentage,
                'indeterminate' => $importIndeterminate,
            ],
        ];

        return [
            'total_rows_known' => $totalKnown,
            'progress_percentage' => $progressPercentage,
            'stages' => $stages,
        ];
    }

    /**
     * Get paginated import history ordered by creation time descending.
     *
     * GET /api/imports
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $imports = $request->user()->imports()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($imports, 200);
    }

    /**
     * Get paginated validation errors for an import.
     *
     * GET /api/imports/{id}/errors
     */
    public function errors(Request $request, int $id): JsonResponse
    {
        $import = Import::find($id);
        if (! $import) {
            return response()->json([
                'message' => "Import [{$id}] not found.",
            ], 404);
        }

        if ($import->user_id === null || (int) $request->user()->id !== (int) $import->user_id) {
            return response()->json([
                'message' => 'This action is unauthorized.',
            ], 403);
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $errors = $import->rowErrors()
            ->select(['id', 'import_id', 'excel_row', 'customer_id', 'rejection_reason', 'created_at'])
            ->orderBy('excel_row', 'asc')
            ->paginate($perPage);

        return response()->json($errors, 200);
    }

    /**
     * Stream CSV error report download.
     *
     * GET /api/imports/{id}/errors.csv
     */
    public function downloadErrorsCsv(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $import = Import::find($id);
        if (! $import) {
            return response()->json([
                'message' => "Import [{$id}] not found.",
            ], 404);
        }

        if ($import->user_id === null || (int) $request->user()->id !== (int) $import->user_id) {
            return response()->json([
                'message' => 'This action is unauthorized.',
            ], 403);
        }

        $filename = "import_{$import->id}_errors.csv";

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        $callback = function () use ($import) {
            $handle = fopen('php://output', 'w');

            // Write UTF-8 BOM for clean Excel rendering
            fwrite($handle, "\xEF\xBB\xBF");

            // CSV Header Row
            fputcsv($handle, ['Excel Row', 'Customer ID', 'Rejection Reason', 'Timestamp']);

            // Stream errors in batches
            $import->rowErrors()
                ->orderBy('excel_row', 'asc')
                ->chunk(500, function ($errors) use ($handle) {
                    foreach ($errors as $error) {
                        fputcsv($handle, [
                            $error->excel_row,
                            $this->sanitizeCsvCell((string) ($error->customer_id ?? 'N/A')),
                            $this->sanitizeCsvCell($error->rejection_reason),
                            $error->created_at?->toIso8601String() ?? '',
                        ]);
                    }
                });

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Protect against CSV / Spreadsheet formula injection.
     * If a cell begins with =, +, -, @, \t, or \r, prepend a single quote.
     */
    protected function sanitizeCsvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Download a sample Excel spreadsheet (.xlsx) with 20 sample rows.
     *
     * GET /api/imports/sample-template
     */
    public function downloadSampleTemplate(): BinaryFileResponse
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'sample_template_').'.xlsx';
        $this->parserService->generateSampleTemplate($tmpPath);

        return response()->download(
            $tmpPath,
            'sample_import_template.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        )->deleteFileAfterSend(true);
    }
}
