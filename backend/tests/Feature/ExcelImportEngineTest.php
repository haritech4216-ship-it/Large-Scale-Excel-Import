<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportJob;
use App\Models\Customer;
use App\Models\Import;
use App\Models\ImportChunk;
use App\Models\ImportRowError;
use App\Services\CustomerRowValidator;
use App\Services\ExcelParserService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\CreatesXlsxFiles;
use Tests\TestCase;

class ExcelImportEngineTest extends TestCase
{
    use CreatesXlsxFiles, DatabaseTransactions;

    protected ExcelParserService $parserService;

    protected CustomerRowValidator $rowValidator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parserService = new ExcelParserService;
        $this->rowValidator = new CustomerRowValidator;
        Storage::fake('local');
    }

    /**
     * Helper to create an Import record with an actual test .xlsx file in storage.
     */
    protected function createImportWithRows(array $rows): array
    {
        $localPath = $this->createXlsxFile($rows);
        $storedPath = Storage::disk('local')->putFileAs('imports', new File($localPath), 'test_'.uniqid().'.xlsx');

        $import = Import::create([
            'filename' => 'test_data.xlsx',
            'file_path' => $storedPath,
            'status' => Import::STATUS_QUEUED,
        ]);

        return [$import, $localPath, $storedPath];
    }

    public function test_valid_rows_imported_when_other_rows_are_invalid(): void
    {
        // Row 2: Valid
        // Row 3: Invalid Email
        // Row 4: Valid
        [$import, $localFile] = $this->createImportWithRows([
            ['CUST_001', 'John', 'Doe', 'john@example.com', '+1-555-0101', '1985-01-01', 'Male', 'Engineer', 'Acme', 'USA', 'New York', '123 Main', 'https://twitter.com', '2023-01-01', 'Active'],
            ['CUST_002', 'Jane', 'Doe', 'INVALID_EMAIL_ADDR', '+1-555-0102', '1988-02-02', 'Female', 'Designer', 'Acme', 'USA', 'New York', '124 Main', '', '2023-01-02', 'Active'],
            ['CUST_003', 'Bob', 'Smith', 'bob@example.com', '+1-555-0103', '1992-03-03', 'Male', 'Manager', 'Corp', 'UK', 'London', '', '', '2023-01-03', 'Inactive'],
        ]);

        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $import->refresh();

        $this->assertSame(Import::STATUS_COMPLETED_WITH_ERRORS, $import->status);
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(3, $import->processed_rows);
        $this->assertSame(2, $import->saved_rows);
        $this->assertSame(1, $import->rejected_rows);

        // Valid rows saved
        $this->assertDatabaseHas('customers', ['customer_id' => 'CUST_001']);
        $this->assertDatabaseHas('customers', ['customer_id' => 'CUST_003']);
        // Invalid row not saved
        $this->assertDatabaseMissing('customers', ['customer_id' => 'CUST_002']);

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    public function test_invalid_rows_retain_correct_excel_row_numbers_and_reasons(): void
    {
        // Excel row 1 = Header
        // Excel row 2 = Valid
        // Excel row 3 = Invalid (Bad DOB)
        // Excel row 4 = Invalid (Missing First Name)
        [$import, $localFile] = $this->createImportWithRows([
            ['CUST_101', 'John', 'Doe', 'john@example.com', '+1-555-0101', '1985-01-01', 'Male', 'Engineer', 'Acme', 'USA', 'New York', '', '', '2023-01-01', 'Active'],
            ['CUST_102', 'Jane', 'Doe', 'jane@example.com', '+1-555-0102', 'NOT_A_DATE', 'Female', 'Designer', 'Acme', 'USA', 'New York', '', '', '2023-01-02', 'Active'],
            ['CUST_103', '', 'Smith', 'bob@example.com', '+1-555-0103', '1992-03-03', 'Male', 'Manager', 'Corp', 'UK', 'London', '', '', '2023-01-03', 'Inactive'],
        ]);

        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $errors = ImportRowError::where('import_id', $import->id)->orderBy('excel_row')->get();

        $this->assertCount(2, $errors);
        $this->assertSame(3, $errors[0]->excel_row);
        $this->assertSame('CUST_102', $errors[0]->customer_id);
        $this->assertStringContainsString('Date of Birth (DOB) is required and must be a valid date.', $errors[0]->rejection_reason);

        $this->assertSame(4, $errors[1]->excel_row);
        $this->assertSame('CUST_103', $errors[1]->customer_id);
        $this->assertStringContainsString('First Name is required.', $errors[1]->rejection_reason);

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    public function test_duplicate_customer_ids_within_file_handled_deterministically(): void
    {
        // Excel row 2: First appearance of CUST_DUP (accepted)
        // Excel row 3: Different customer (accepted)
        // Excel row 4: Second appearance of CUST_DUP (rejected)
        // Excel row 5: Third appearance of CUST_DUP (rejected)
        [$import, $localFile] = $this->createImportWithRows([
            ['CUST_DUP', 'First', 'User', 'first@example.com', '+1-555-0001', '1990-01-01', 'Male', 'Dev', '', 'USA', 'LA', '', '', '2023-01-01', 'Active'],
            ['CUST_UNIQ', 'Other', 'User', 'other@example.com', '+1-555-0002', '1991-01-01', 'Female', 'Dev', '', 'USA', 'LA', '', '', '2023-01-01', 'Active'],
            ['CUST_DUP', 'Second', 'User', 'second@example.com', '+1-555-0003', '1992-01-01', 'Male', 'Dev', '', 'USA', 'LA', '', '', '2023-01-01', 'Active'],
            ['CUST_DUP', 'Third', 'User', 'third@example.com', '+1-555-0004', '1993-01-01', 'Male', 'Dev', '', 'USA', 'LA', '', '', '2023-01-01', 'Active'],
        ]);

        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $import->refresh();

        $this->assertSame(4, $import->total_rows);
        $this->assertSame(2, $import->saved_rows);
        $this->assertSame(2, $import->rejected_rows);

        // Only the first customer record was inserted
        $saved = Customer::where('customer_id', 'CUST_DUP')->get();
        $this->assertCount(1, $saved);
        $this->assertSame('First', $saved->first()->first_name);

        // Rows 4 and 5 were rejected with clear deterministic reasons referencing the first row
        $errors = ImportRowError::where('import_id', $import->id)->where('customer_id', 'CUST_DUP')->orderBy('excel_row')->get();
        $this->assertCount(2, $errors);
        $this->assertSame(4, $errors[0]->excel_row);
        $this->assertStringContainsString("Duplicate Customer ID 'CUST_DUP' within file (first seen at row 2).", $errors[0]->rejection_reason);

        $this->assertSame(5, $errors[1]->excel_row);
        $this->assertStringContainsString("Duplicate Customer ID 'CUST_DUP' within file (first seen at row 2).", $errors[1]->rejection_reason);

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    public function test_existing_database_customer_ids_are_rejected_without_overwriting(): void
    {
        // Pre-create customer in the database
        Customer::create([
            'customer_id' => 'CUST_EXISTING',
            'first_name' => 'Original',
            'last_name' => 'Person',
            'email' => 'original@example.com',
            'phone_number' => '+1-555-9999',
            'date_of_birth' => '1980-01-01',
            'profession' => 'Engineer',
            'country' => 'USA',
            'city' => 'Chicago',
            'registration_date' => '2020-01-01',
            'status' => 'Active',
        ]);

        // Excel file contains an entry trying to insert CUST_EXISTING with new values
        [$import, $localFile] = $this->createImportWithRows([
            ['CUST_EXISTING', 'Overwritten?', 'Hacker', 'hacked@example.com', '+1-555-0000', '1999-01-01', 'Male', 'Job', '', 'USA', 'LA', '', '', '2024-01-01', 'Active'],
            ['CUST_NEW', 'New', 'Customer', 'new@example.com', '+1-555-1111', '1995-01-01', 'Female', 'Job', '', 'USA', 'LA', '', '', '2024-01-01', 'Active'],
        ]);

        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $import->refresh();

        $this->assertSame(1, $import->saved_rows);
        $this->assertSame(1, $import->rejected_rows);

        // Pre-existing customer must NOT be overwritten
        $original = Customer::where('customer_id', 'CUST_EXISTING')->first();
        $this->assertSame('Original', $original->first_name);
        $this->assertSame('original@example.com', $original->email);

        // Error recorded for the rejected duplicate
        $error = ImportRowError::where('import_id', $import->id)->where('customer_id', 'CUST_EXISTING')->first();
        $this->assertNotNull($error);
        $this->assertSame(2, $error->excel_row);
        $this->assertStringContainsString("Customer ID 'CUST_EXISTING' already exists in database.", $error->rejection_reason);

        // New customer saved
        $this->assertDatabaseHas('customers', ['customer_id' => 'CUST_NEW']);

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    public function test_reprocessing_completed_chunk_is_idempotent_and_does_not_duplicate(): void
    {
        [$import, $localFile] = $this->createImportWithRows([
            ['CUST_CHUNK_1', 'Name1', 'Last1', 'user1@example.com', '+1-555-0001', '1990-01-01', '', 'Dev', '', 'USA', 'NY', '', '', '2023-01-01', 'Active'],
            ['CUST_CHUNK_2', 'Name2', 'Last2', 'user2@example.com', '+1-555-0002', '1990-01-01', '', 'Dev', '', 'USA', 'NY', '', '', '2023-01-01', 'Active'],
        ]);

        // First execution
        $job1 = new ProcessImportJob($import->id);
        $job1->handle($this->parserService, $this->rowValidator);

        $import->refresh();
        $this->assertSame(2, $import->saved_rows);
        $this->assertSame(2, $import->processed_rows);

        $chunk = ImportChunk::where('import_id', $import->id)->where('chunk_number', 1)->first();
        $this->assertNotNull($chunk);
        $this->assertSame(ImportChunk::STATUS_COMPLETED, $chunk->status);

        // Reset import status to Processing to simulate a job retry of the file
        $import->update(['status' => Import::STATUS_PROCESSING]);

        // Second execution (reprocessing the exact same completed chunk)
        $job2 = new ProcessImportJob($import->id);
        $job2->handle($this->parserService, $this->rowValidator);

        $import->refresh();

        // Saved rows must still be exactly 2 (not 4), and no duplicate customers created
        $this->assertSame(2, $import->saved_rows);
        $this->assertSame(2, $import->processed_rows);
        $this->assertSame(1, Customer::where('customer_id', 'CUST_CHUNK_1')->count());
        $this->assertSame(1, Customer::where('customer_id', 'CUST_CHUNK_2')->count());

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    public function test_failed_chunk_can_be_retried_safely(): void
    {
        [$import, $localFile] = $this->createImportWithRows([
            ['CUST_RETRY_1', 'Retry1', 'Last', 'retry1@example.com', '+1-555-0001', '1990-01-01', '', 'Dev', '', 'USA', 'NY', '', '', '2023-01-01', 'Active'],
        ]);

        // Manually create a failed chunk record
        ImportChunk::create([
            'import_id' => $import->id,
            'chunk_number' => 1,
            'start_row' => 2,
            'end_row' => 2,
            'status' => ImportChunk::STATUS_FAILED,
            'attempts' => 1,
            'error_message' => 'Simulated previous crash',
        ]);

        // Run the job
        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(1, $import->saved_rows);

        // Chunk was retried and updated to completed with incremented attempts
        $chunk = ImportChunk::where('import_id', $import->id)->where('chunk_number', 1)->first();
        $this->assertSame(ImportChunk::STATUS_COMPLETED, $chunk->status);
        $this->assertGreaterThanOrEqual(2, $chunk->attempts);

        $this->assertDatabaseHas('customers', ['customer_id' => 'CUST_RETRY_1']);

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    public function test_concurrent_chunk_processing_cannot_import_same_chunk_twice(): void
    {
        [$import, $localFile] = $this->createImportWithRows([
            ['CUST_CONCUR_1', 'Concur', 'User', 'concur@example.com', '+1-555-0001', '1990-01-01', '', 'Dev', '', 'USA', 'NY', '', '', '2023-01-01', 'Active'],
        ]);

        // Pre-claim chunk as completed (simulating Worker 1 finished it just moments before Worker 2)
        ImportChunk::create([
            'import_id' => $import->id,
            'chunk_number' => 1,
            'start_row' => 2,
            'end_row' => 2,
            'status' => ImportChunk::STATUS_COMPLETED,
            'total_rows' => 1,
            'processed_rows' => 1,
            'saved_rows' => 1,
            'rejected_rows' => 0,
            'attempts' => 1,
            'completed_at' => now(),
        ]);

        Customer::create([
            'customer_id' => 'CUST_CONCUR_1',
            'first_name' => 'Concur',
            'last_name' => 'User',
            'email' => 'concur@example.com',
            'phone_number' => '+1-555-0001',
            'date_of_birth' => '1990-01-01',
            'profession' => 'Dev',
            'country' => 'USA',
            'city' => 'NY',
            'registration_date' => '2023-01-01',
            'status' => 'Active',
            'import_id' => $import->id,
        ]);

        $import->update([
            'processed_rows' => 1,
            'saved_rows' => 1,
        ]);

        // Worker 2 attempts to process
        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $import->refresh();

        // Progress was not double counted
        $this->assertSame(1, $import->saved_rows);
        $this->assertSame(1, Customer::where('customer_id', 'CUST_CONCUR_1')->count());

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    public function test_concurrent_duplicate_conflict_in_later_sub_batch_does_not_reject_earlier_sub_batch(): void
    {
        config(['excel_import.chunk_size' => 600]);

        // Generate 520 rows: 500 rows for sub-batch 1, 20 rows for sub-batch 2
        $rows = [];
        for ($i = 1; $i <= 520; $i++) {
            $rows[] = [
                "CUST_MULTI_{$i}",
                'User',
                "Test{$i}",
                "user{$i}@example.com",
                '+1-555-0100',
                '1990-01-01',
                'Male',
                'Engineer',
                'Acme',
                'USA',
                'NY',
                '123 Street',
                '',
                '2024-01-01',
                'Active',
            ];
        }

        [$import, $localFile] = $this->createImportWithRows($rows);

        // Simulate concurrent insertion of CUST_MULTI_510 (row in sub-batch 2) right after the pre-check query runs
        $injected = false;
        DB::listen(function ($query) use (&$injected) {
            if (! $injected && str_contains(strtolower($query->sql), 'from `customers` where `customer_id` in')) {
                $injected = true;
                Customer::create([
                    'customer_id' => 'CUST_MULTI_510',
                    'first_name' => 'Concurrent',
                    'last_name' => 'Winner',
                    'email' => 'winner@example.com',
                    'phone_number' => '+1-555-9999',
                    'date_of_birth' => '1985-05-05',
                    'profession' => 'Architect',
                    'country' => 'USA',
                    'city' => 'NY',
                    'registration_date' => '2023-01-01',
                    'status' => 'Active',
                ]);
            }
        });

        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $import->refresh();

        // 520 total rows: 519 inserted successfully, 1 rejected due to race condition conflict
        $this->assertSame(520, $import->total_rows);
        $this->assertSame(520, $import->processed_rows);
        $this->assertSame(519, $import->saved_rows);
        $this->assertSame(1, $import->rejected_rows);
        $this->assertSame(Import::STATUS_COMPLETED_WITH_ERRORS, $import->status);

        // Check that records from Sub-batch 1 (1 to 500) are ALL saved in DB and NOT rejected
        $this->assertSame(1, Customer::where('customer_id', 'CUST_MULTI_1')->count());
        $this->assertSame(1, Customer::where('customer_id', 'CUST_MULTI_500')->count());

        // Check that records from Sub-batch 2 are also saved (except the conflicting one)
        $this->assertSame(1, Customer::where('customer_id', 'CUST_MULTI_501')->count());
        $this->assertSame(1, Customer::where('customer_id', 'CUST_MULTI_520')->count());

        // Verify the single error recorded
        $errors = ImportRowError::where('import_id', $import->id)->get();
        $this->assertCount(1, $errors);
        $error = $errors->first();
        $this->assertSame('CUST_MULTI_510', $error->customer_id);
        // Physical Excel row: row 1 is header, so row 510 in data array is physical row 511
        $this->assertSame(511, $error->excel_row);
        $this->assertNotSame(0, $error->excel_row);
        $this->assertStringContainsString("Customer ID 'CUST_MULTI_510' was inserted concurrently in another process.", $error->rejection_reason);

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    public function test_duplicate_race_error_always_records_physical_excel_row_number_never_zero(): void
    {
        [$import, $localFile] = $this->createImportWithRows([
            ['CUST_RACE_1', 'One', 'User', 'one@example.com', '+1-555-0001', '1990-01-01', '', 'Dev', '', 'USA', 'NY', '', '', '2023-01-01', 'Active'],
            ['CUST_RACE_2', 'Two', 'User', 'two@example.com', '+1-555-0002', '1990-01-01', '', 'Dev', '', 'USA', 'NY', '', '', '2023-01-01', 'Active'],
        ]);

        // Inject CUST_RACE_2 concurrently
        $injected = false;
        DB::listen(function ($query) use (&$injected) {
            if (! $injected && str_contains(strtolower($query->sql), 'from `customers` where `customer_id` in')) {
                $injected = true;
                Customer::create([
                    'customer_id' => 'CUST_RACE_2',
                    'first_name' => 'Existing',
                    'last_name' => 'Race',
                    'email' => 'race@example.com',
                    'phone_number' => '+1-555-0000',
                    'date_of_birth' => '1990-01-01',
                    'profession' => 'Dev',
                    'country' => 'USA',
                    'city' => 'NY',
                    'registration_date' => '2023-01-01',
                    'status' => 'Active',
                ]);
            }
        });

        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $error = ImportRowError::where('import_id', $import->id)->first();
        $this->assertNotNull($error);
        $this->assertSame('CUST_RACE_2', $error->customer_id);
        $this->assertSame(3, $error->excel_row); // Row 1 header, Row 2 CUST_RACE_1, Row 3 CUST_RACE_2
        $this->assertNotSame(0, $error->excel_row);

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    public function test_retryable_job_failure_does_not_mark_import_failed_before_retries_exhausted(): void
    {
        $storedPath = 'imports/test_retry.xlsx';
        Storage::disk('local')->put($storedPath, 'dummy binary content');

        $import = Import::create([
            'filename' => 'test.xlsx',
            'file_path' => $storedPath,
            'status' => Import::STATUS_QUEUED,
        ]);

        $job = new ProcessImportJob($import->id);

        // Mock an active queue job on attempt 1 of 3
        $mockQueueJob = Mockery::mock(Job::class);
        $mockQueueJob->shouldReceive('attempts')->andReturn(1);
        $job->setJob($mockQueueJob);

        // Mock parser service to throw transient error during handle
        $mockParser = Mockery::mock(ExcelParserService::class);
        $mockParser->shouldReceive('detectTotalRows')->andReturn(null);
        $mockParser->shouldReceive('validateHeaders')->andThrow(new RuntimeException('Transient lock wait timeout'));

        // Job should re-throw exception without marking the import permanently failed
        try {
            $job->handle($mockParser, $this->rowValidator);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Transient lock wait timeout', $e->getMessage());
        }

        $import->refresh();

        // Import must remain in active Processing state, NOT Failed
        $this->assertSame(Import::STATUS_PROCESSING, $import->status);
        $this->assertNull($import->failure_reason);
        $this->assertNull($import->completed_at);
    }

    public function test_final_job_failure_on_exhausted_retries_marks_import_as_failed(): void
    {
        $storedPath = 'imports/test_final.xlsx';
        Storage::disk('local')->put($storedPath, 'dummy binary content');

        $import = Import::create([
            'filename' => 'test.xlsx',
            'file_path' => $storedPath,
            'status' => Import::STATUS_QUEUED,
        ]);

        $job = new ProcessImportJob($import->id);

        // Mock an active queue job on final attempt 3 of 3
        $mockQueueJob = Mockery::mock(Job::class);
        $mockQueueJob->shouldReceive('attempts')->andReturn(3);
        $job->setJob($mockQueueJob);

        $mockParser = Mockery::mock(ExcelParserService::class);
        $mockParser->shouldReceive('detectTotalRows')->andReturn(null);
        $mockParser->shouldReceive('validateHeaders')->andThrow(new RuntimeException('Permanent parse error on host=127.0.0.1 user=excel_user password=secret123'));

        try {
            $job->handle($mockParser, $this->rowValidator);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Permanent parse error', $e->getMessage());
        }

        $import->refresh();

        // Terminal attempt marks import as Failed with sanitized error
        $this->assertSame(Import::STATUS_FAILED, $import->status);
        $this->assertNotNull($import->completed_at);
        $this->assertNotNull($import->failure_reason);
        $this->assertStringNotContainsString('password=secret123', $import->failure_reason);
        $this->assertStringContainsString('[redacted]', $import->failure_reason);
    }

    public function test_failed_hook_marks_import_failed_with_sanitized_reason(): void
    {
        $import = Import::create([
            'filename' => 'hook_test.xlsx',
            'file_path' => 'imports/hook.xlsx',
            'status' => Import::STATUS_PROCESSING,
        ]);

        $job = new ProcessImportJob($import->id);
        $job->failed(new RuntimeException('Worker killed due to max memory /home/harishankar/server'));

        $import->refresh();

        $this->assertSame(Import::STATUS_FAILED, $import->status);
        $this->assertNotNull($import->completed_at);
        $this->assertStringContainsString('Worker killed due to max memory [server-path]', $import->failure_reason);
        $this->assertStringNotContainsString('/home/harishankar', $import->failure_reason);
    }

    /**
     * Test defensive handling of customer_id length and nullability at error persistence boundary.
     * Verifies:
     *  1. Missing customer_id is persisted as NULL.
     *  2. A valid customer_id is persisted unchanged.
     *  3. An oversized 110-character customer_id is persisted safely within 100 characters.
     *  4. The original rejection reason remains unchanged.
     *  5. Multiple rejected rows can be persisted in the same bulk batch.
     *  6. One oversized rejected ID does not cause the entire error batch to fail.
     *  7. Existing validation behavior remains unchanged.
     */
    public function test_oversized_and_missing_customer_ids_persist_safely_in_error_batch(): void
    {
        $oversizedId110 = 'CUST_OVERSIZED_'.str_repeat('X', 95); // 15 + 95 = 110 characters
        $this->assertSame(110, strlen($oversizedId110));
        $expectedTruncated100 = substr($oversizedId110, 0, 100);

        // Excel rows:
        // Row 1: Header
        // Row 2: Valid record (CUST_VALID_A) -> should be imported into customers
        // Row 3: Missing customer_id ('') -> rejected, customer_id persisted as NULL
        // Row 4: Valid customer_id ('CUST_VALID_REJECTED') with invalid email -> rejected, customer_id persisted unchanged
        // Row 5: Oversized 110-char customer_id -> rejected, customer_id persisted safely truncated to 100 chars
        // Row 6: Valid record (CUST_VALID_B) -> should be imported into customers
        [$import, $localFile] = $this->createImportWithRows([
            ['CUST_VALID_A', 'Alice', 'Smith', 'alice@example.com', '+1-555-0101', '1990-01-01', 'Female', 'Engineer', 'Acme', 'USA', 'New York', '', '', '2023-01-01', 'Active'],
            ['', 'NoId', 'User', 'noid@example.com', '+1-555-0102', '1991-02-02', 'Male', 'Analyst', 'Acme', 'USA', 'Chicago', '', '', '2023-01-02', 'Active'],
            ['CUST_VALID_REJECTED', 'Bob', 'Jones', 'not-an-email', '+1-555-0103', '1992-03-03', 'Male', 'Designer', 'Acme', 'USA', 'Boston', '', '', '2023-01-03', 'Active'],
            [$oversizedId110, 'Charlie', 'Brown', 'charlie@example.com', '+1-555-0104', '1993-04-04', 'Male', 'Manager', 'Acme', 'USA', 'Dallas', '', '', '2023-01-04', 'Active'],
            ['CUST_VALID_B', 'Diana', 'Prince', 'diana@example.com', '+1-555-0105', '1994-05-05', 'Female', 'Director', 'Acme', 'USA', 'Austin', '', '', '2023-01-05', 'Active'],
        ]);

        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $import->refresh();

        // Verification 7: Existing validation behavior and import status
        $this->assertSame(Import::STATUS_COMPLETED_WITH_ERRORS, $import->status);
        $this->assertSame(5, $import->processed_rows);
        $this->assertSame(2, $import->saved_rows);
        $this->assertSame(3, $import->rejected_rows);
        $this->assertSame(3, $import->invalid_rows);

        // Verification: Valid rows are saved into customers
        $this->assertDatabaseHas('customers', ['customer_id' => 'CUST_VALID_A', 'import_id' => $import->id]);
        $this->assertDatabaseHas('customers', ['customer_id' => 'CUST_VALID_B', 'import_id' => $import->id]);
        $this->assertDatabaseMissing('customers', ['customer_id' => $oversizedId110]);
        $this->assertDatabaseMissing('customers', ['customer_id' => $expectedTruncated100]);

        // Verification 5 & 6: Multiple rejected rows in the same bulk batch persisted; oversized ID did not fail the batch
        $errors = ImportRowError::where('import_id', $import->id)->orderBy('excel_row')->get();
        $this->assertCount(3, $errors);

        // Verification 1: Missing customer_id is persisted as NULL
        $this->assertSame(3, $errors[0]->excel_row);
        $this->assertNull($errors[0]->customer_id);
        $this->assertStringContainsString('Customer ID is required.', $errors[0]->rejection_reason);

        // Verification 2: A valid customer_id is persisted unchanged
        $this->assertSame(4, $errors[1]->excel_row);
        $this->assertSame('CUST_VALID_REJECTED', $errors[1]->customer_id);
        $this->assertStringContainsString('Email must be a valid email address.', $errors[1]->rejection_reason);

        // Verification 3 & 4: Oversized 110-character customer_id is persisted safely within 100 characters,
        // and the original rejection reason remains unchanged
        $this->assertSame(5, $errors[2]->excel_row);
        $this->assertNotNull($errors[2]->customer_id);
        $this->assertSame(100, strlen($errors[2]->customer_id));
        $this->assertSame($expectedTruncated100, $errors[2]->customer_id);
        $this->assertSame('Customer ID must not exceed 100 characters.', $errors[2]->rejection_reason);

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    /**
     * Test multibyte-safe truncation for oversized customer IDs exceeding 100 characters.
     */
    public function test_multibyte_oversized_customer_id_is_safely_truncated(): void
    {
        // 10 chars prefix + 101 multibyte characters = 110 multibyte characters
        $multibyteOversized = 'CUST_ÜÖÄ_'.str_repeat('é', 101);
        $this->assertSame(110, mb_strlen($multibyteOversized));
        $expectedTruncated = mb_substr($multibyteOversized, 0, 100);
        $this->assertSame(100, mb_strlen($expectedTruncated));

        [$import, $localFile] = $this->createImportWithRows([
            [$multibyteOversized, 'Multibyte', 'User', 'mb@example.com', '+1-555-0101', '1990-01-01', 'Male', 'Tester', 'Acme', 'USA', 'New York', '', '', '2023-01-01', 'Active'],
        ]);

        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $error = ImportRowError::where('import_id', $import->id)->first();
        $this->assertNotNull($error);
        $this->assertSame(100, mb_strlen($error->customer_id));
        $this->assertSame($expectedTruncated, $error->customer_id);
        $this->assertSame('Customer ID must not exceed 100 characters.', $error->rejection_reason);

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    /**
     * Test Verification 8: Existing chunk idempotency behavior remains unchanged with oversized rejected IDs.
     */
    public function test_oversized_rejected_rows_preserve_chunk_idempotency_on_reprocess(): void
    {
        $oversizedId = 'CUST_OVERSIZED_'.str_repeat('Z', 95);

        [$import, $localFile] = $this->createImportWithRows([
            ['CUST_IDEM_1', 'Valid', 'User', 'valid@example.com', '+1-555-0101', '1990-01-01', 'Male', 'Dev', 'Acme', 'USA', 'City', '', '', '2023-01-01', 'Active'],
            [$oversizedId, 'Oversized', 'User', 'oversized@example.com', '+1-555-0102', '1990-01-01', 'Male', 'Dev', 'Acme', 'USA', 'City', '', '', '2023-01-01', 'Active'],
        ]);

        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED_WITH_ERRORS, $import->status);
        $this->assertSame(1, $import->saved_rows);
        $this->assertSame(1, $import->rejected_rows);

        // Verify chunk was marked as completed
        $chunk = ImportChunk::where('import_id', $import->id)->where('chunk_number', 1)->first();
        $this->assertNotNull($chunk);
        $this->assertSame(ImportChunk::STATUS_COMPLETED, $chunk->status);

        $initialCustomerCount = Customer::where('import_id', $import->id)->count();
        $initialErrorCount = ImportRowError::where('import_id', $import->id)->count();
        $this->assertSame(1, $initialCustomerCount);
        $this->assertSame(1, $initialErrorCount);

        // Reprocess the same job - chunk idempotency must skip completed chunk without duplicates
        $retryJob = new ProcessImportJob($import->id);
        $retryJob->handle($this->parserService, $this->rowValidator);

        $this->assertSame($initialCustomerCount, Customer::where('import_id', $import->id)->count());
        $this->assertSame($initialErrorCount, ImportRowError::where('import_id', $import->id)->count());

        if (file_exists($localFile)) {
            unlink($localFile);
        }
    }

    /**
     * Test direct defensive sanitization helper unit contracts.
     */
    public function test_sanitize_customer_id_helper_contracts(): void
    {
        $job = new ProcessImportJob(999);

        // Contract 1: NULL returns NULL
        $this->assertNull($job->sanitizeCustomerIdForErrorPersistence(null));

        // Contract 2: Empty string or spaces return NULL
        $this->assertNull($job->sanitizeCustomerIdForErrorPersistence(''));
        $this->assertNull($job->sanitizeCustomerIdForErrorPersistence('   '));

        // Contract 3: Valid ID returned unchanged
        $this->assertSame('CUST-1001', $job->sanitizeCustomerIdForErrorPersistence('CUST-1001'));

        // Contract 4: Oversized 110 chars truncated to 100
        $input110 = 'CUST_'.str_repeat('A', 105);
        $result = $job->sanitizeCustomerIdForErrorPersistence($input110);
        $this->assertSame(100, strlen($result));
        $this->assertSame(substr($input110, 0, 100), $result);

        // Contract 5: Multibyte characters safely truncated
        $mbInput = 'ID_'.str_repeat('日本語', 35); // 3 + 105 = 108 chars
        $mbResult = $job->sanitizeCustomerIdForErrorPersistence($mbInput);
        $this->assertSame(100, mb_strlen($mbResult));
        $this->assertSame(mb_substr($mbInput, 0, 100), $mbResult);
    }
}
