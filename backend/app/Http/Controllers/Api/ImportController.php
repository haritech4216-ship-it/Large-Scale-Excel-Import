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

        // Create import record with Queued status
        $import = Import::create([
            'filename' => $originalFilename,
            'file_path' => $storedPath,
            'status' => Import::STATUS_QUEUED,
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
    public function show(int $id): JsonResponse
    {
        $import = Import::find($id);
        if (! $import) {
            return response()->json([
                'message' => "Import [{$id}] not found.",
            ], 404);
        }

        $progressPercentage = 0.0;
        if ($import->total_rows > 0) {
            $progressPercentage = round(($import->processed_rows / $import->total_rows) * 100, 1);
            if ($progressPercentage > 100.0) {
                $progressPercentage = 100.0;
            }
        }

        return response()->json([
            'id' => $import->id,
            'filename' => $import->filename,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'saved_rows' => $import->saved_rows,
            'rejected_rows' => $import->rejected_rows,
            'progress_percentage' => $progressPercentage,
            'started_at' => $import->started_at?->toISOString(),
            'completed_at' => $import->completed_at?->toISOString(),
            'duration_seconds' => $import->duration_seconds,
            'failure_reason' => $import->failure_reason,
            'created_at' => $import->created_at->toISOString(),
            'updated_at' => $import->updated_at->toISOString(),
        ], 200);
    }

    /**
     * Get paginated import history ordered by creation time descending.
     *
     * GET /api/imports
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $imports = Import::query()
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
    public function downloadErrorsCsv(int $id): StreamedResponse|JsonResponse
    {
        $import = Import::find($id);
        if (! $import) {
            return response()->json([
                'message' => "Import [{$id}] not found.",
            ], 404);
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
}
