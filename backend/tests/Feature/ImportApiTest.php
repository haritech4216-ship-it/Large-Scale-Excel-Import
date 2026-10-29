<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\ImportRowError;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesXlsxFiles;
use Tests\TestCase;

class ImportApiTest extends TestCase
{
    use CreatesXlsxFiles, DatabaseTransactions;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_valid_xlsx_upload_queues_import_and_returns_import_id(): void
    {
        $filePath = $this->createXlsxFile([
            ['CUST_001', 'John', 'Doe', 'john@example.com', '+1-555-0100', '1990-01-01', 'Male', 'Teacher', 'School', 'USA', 'Boston', '123 Main St', 'https://example.com', '2024-01-01', 'Active'],
        ]);

        $uploadedFile = new UploadedFile(
            $filePath,
            'customers.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->postJson('/api/imports', [
            'file' => $uploadedFile,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'filename',
                'status',
                'message',
            ])
            ->assertJson([
                'filename' => 'customers.xlsx',
                'status' => 'Queued',
            ]);

        $importId = $response->json('id');
        $this->assertDatabaseHas('imports', [
            'id' => $importId,
            'filename' => 'customers.xlsx',
            'status' => 'Queued',
        ]);

        Queue::assertPushed(ProcessImportJob::class, function ($job) use ($importId) {
            return $job->importId === $importId;
        });

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    public function test_invalid_file_extension_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('test.csv', 100, 'text/csv');

        $response = $this->postJson('/api/imports', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_invalid_workbook_content_is_rejected(): void
    {
        // Fake file with .xlsx extension but plain text content
        $file = UploadedFile::fake()->createWithContent('corrupt.xlsx', 'this is plain text not a zip file');

        $response = $this->postJson('/api/imports', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_missing_required_headers_is_rejected(): void
    {
        // Missing Last Name, Email, DOB, etc.
        $filePath = $this->createXlsxFile(
            [['CUST_001', 'John']],
            ['Customer Id', 'First Name']
        );

        $uploadedFile = new UploadedFile(
            $filePath,
            'partial_headers.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->postJson('/api/imports', [
            'file' => $uploadedFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors' => ['file'],
            ]);

        $this->assertStringContainsString('Missing required header', $response->json('errors.file.0'));

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    public function test_oversized_file_is_rejected(): void
    {
        config(['excel_import.max_upload_size_kb' => 10]); // Limit to 10 KB

        $file = UploadedFile::fake()->create('large.xlsx', 500, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->postJson('/api/imports', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_get_import_status_and_progress(): void
    {
        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'batch1.xlsx',
            'file_path' => 'imports/batch1.xlsx',
            'status' => 'Processing',
            'total_rows' => 100,
            'processed_rows' => 50,
            'saved_rows' => 45,
            'rejected_rows' => 5,
            'started_at' => now()->subSeconds(30),
        ]);

        $response = $this->getJson("/api/imports/{$import->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $import->id,
                'filename' => 'batch1.xlsx',
                'status' => 'Processing',
                'total_rows' => 100,
                'processed_rows' => 50,
                'saved_rows' => 45,
                'rejected_rows' => 5,
                'progress_percentage' => 50.0,
            ]);
    }

    public function test_get_unknown_import_returns_404(): void
    {
        $response = $this->getJson('/api/imports/999999');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Import [999999] not found.',
            ]);
    }

    public function test_get_import_history_is_paginated(): void
    {
        Import::create(['user_id' => $this->user->id, 'filename' => 'h1.xlsx', 'file_path' => 'p1', 'status' => 'Completed']);
        Import::create(['user_id' => $this->user->id, 'filename' => 'h2.xlsx', 'file_path' => 'p2', 'status' => 'Queued']);

        $response = $this->getJson('/api/imports?per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'current_page',
                'per_page',
                'total',
            ]);
    }

    public function test_get_import_errors_is_paginated(): void
    {
        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'err_test.xlsx',
            'file_path' => 'p',
            'status' => 'Completed with Errors',
        ]);

        ImportRowError::create([
            'import_id' => $import->id,
            'excel_row' => 2,
            'customer_id' => 'CUST_ERR_1',
            'rejection_reason' => 'Invalid email address.',
        ]);

        $response = $this->getJson("/api/imports/{$import->id}/errors");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'import_id',
                        'excel_row',
                        'customer_id',
                        'rejection_reason',
                    ],
                ],
                'current_page',
                'total',
            ])
            ->assertJsonFragment([
                'excel_row' => 2,
                'customer_id' => 'CUST_ERR_1',
                'rejection_reason' => 'Invalid email address.',
            ]);
    }

    public function test_download_errors_csv_streams_file_with_formula_injection_protection(): void
    {
        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'csv_test.xlsx',
            'file_path' => 'p',
            'status' => 'Completed with Errors',
        ]);

        // Malicious formula injection attempt
        ImportRowError::create([
            'import_id' => $import->id,
            'excel_row' => 5,
            'customer_id' => '=1+1',
            'rejection_reason' => '+cmd|/c calc.exe',
        ]);

        $response = $this->get("/api/imports/{$import->id}/errors.csv");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', "attachment; filename=\"import_{$import->id}_errors.csv\"");

        $content = $response->streamedContent();

        // Formula injection protection must prefix = and + with '
        $this->assertStringContainsString("5,'=1+1", $content);
        $this->assertStringContainsString("'+cmd|/c calc.exe", $content);
    }

    public function test_download_sample_template_returns_valid_xlsx_spreadsheet(): void
    {
        $response = $this->get('/api/imports/sample-template');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('Content-Disposition', 'attachment; filename=sample_import_template.xlsx');

        // Verify the downloaded file structure
        $binary = $response->streamedContent();
        $this->assertNotEmpty($binary);

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_dl_sample_').'.xlsx';
        file_put_contents($tmpFile, $binary);

        $parser = app(\App\Services\ExcelParserService::class);
        $headerMap = $parser->validateHeaders($tmpFile);
        $this->assertCount(15, $headerMap);

        $totalRows = $parser->detectTotalRows($tmpFile);
        $this->assertSame(20, $totalRows);

        unlink($tmpFile);
    }

    public function test_sample_template_can_be_uploaded_and_imported_with_twenty_records(): void
    {
        // 1. Download sample template
        $response = $this->get('/api/imports/sample-template');
        $response->assertStatus(200);

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_sample_up_').'.xlsx';
        file_put_contents($tmpFile, $response->streamedContent());

        // 2. Upload template through import endpoint
        $uploadedFile = new UploadedFile(
            $tmpFile,
            'sample_import_template.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $uploadResponse = $this->postJson('/api/imports', [
            'file' => $uploadedFile,
        ]);

        $uploadResponse->assertStatus(201);
        $importId = $uploadResponse->json('id');
        $this->assertNotNull($importId);

        // 3. Process the import job synchronously
        $import = Import::findOrFail($importId);
        $job = new ProcessImportJob($importId);
        $job->handle(app(\App\Services\ExcelParserService::class), app(\App\Services\CustomerRowValidator::class));

        $import->refresh();

        // 4. Assert all 20 records imported successfully with 0 errors
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(20, $import->total_rows);
        $this->assertSame(20, $import->processed_rows);
        $this->assertSame(20, $import->saved_rows);
        $this->assertSame(0, $import->rejected_rows);

        $detailResponse = $this->getJson("/api/imports/{$importId}");
        $detailResponse->assertStatus(200);
        $this->assertEquals(100.0, $detailResponse->json('progress_percentage'));

        // Check sample customer exists in database
        $this->assertDatabaseHas('customers', ['customer_id' => 'CUST-1001']);
        $this->assertDatabaseHas('customers', ['customer_id' => 'CUST-1020']);

        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
    }
}
