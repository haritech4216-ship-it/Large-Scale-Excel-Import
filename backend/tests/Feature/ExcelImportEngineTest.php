<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportJob;
use App\Models\Customer;
use App\Models\Import;
use App\Models\ImportChunk;
use App\Models\ImportRowError;
use App\Services\CustomerRowValidator;
use App\Services\ExcelParserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
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
}
