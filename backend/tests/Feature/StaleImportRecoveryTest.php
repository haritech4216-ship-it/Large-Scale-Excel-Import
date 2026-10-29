<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportJob;
use App\Models\Customer;
use App\Models\Import;
use App\Models\ImportChunk;
use App\Models\User;
use App\Services\CustomerRowValidator;
use App\Services\ExcelParserService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\File;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\CreatesXlsxFiles;
use Tests\TestCase;

class StaleImportRecoveryTest extends TestCase
{
    use CreatesXlsxFiles, DatabaseTransactions;

    protected User $user;

    protected ExcelParserService $parserService;

    protected CustomerRowValidator $rowValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parserService = new ExcelParserService();
        $this->rowValidator = new CustomerRowValidator();

        Storage::fake('local');

        $this->user = User::factory()->create();
    }

    /**
     * Requirement 1: Failed imports receive completed_at and duration_seconds.
     */
    public function test_failed_imports_receive_completed_at_and_duration_seconds(): void
    {
        $startedAt = now()->subSeconds(45);

        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'fail_test.xlsx',
            'file_path' => 'imports/fail_test.xlsx',
            'status' => Import::STATUS_PROCESSING,
            'started_at' => $startedAt,
        ]);

        $marked = $import->markFailed('Fatal unrecoverable error during row validation.');

        $this->assertTrue($marked);
        $import->refresh();

        $this->assertSame(Import::STATUS_FAILED, $import->status);
        $this->assertSame('Fatal unrecoverable error during row validation.', $import->failure_reason);
        $this->assertNotNull($import->completed_at);
        $this->assertNotNull($import->duration_seconds);
        $this->assertGreaterThanOrEqual(44.0, $import->duration_seconds);
        $this->assertLessThanOrEqual(47.0, $import->duration_seconds);
    }

    /**
     * Requirement 1b: Failed imports handle missing started_at safely.
     */
    public function test_failed_imports_handle_missing_started_at_safely(): void
    {
        $createdAt = now()->subSeconds(15);

        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'early_fail.xlsx',
            'file_path' => 'imports/early_fail.xlsx',
            'status' => Import::STATUS_QUEUED,
            'started_at' => null,
            'created_at' => $createdAt,
        ]);
        DB::table('imports')->where('id', $import->id)->update(['created_at' => $createdAt]);
        $import->refresh();

        $import->markFailed('Pre-flight validation failed before started_at was set.');
        $import->refresh();

        $this->assertSame(Import::STATUS_FAILED, $import->status);
        $this->assertNotNull($import->completed_at);
        $this->assertNotNull($import->duration_seconds);
        $this->assertGreaterThanOrEqual(14.0, $import->duration_seconds);
    }

    /**
     * Requirement 2: Repeated failure handling does not alter finalized metadata.
     */
    public function test_repeated_failure_handling_does_not_alter_finalized_metadata(): void
    {
        $startedAt = now()->subSeconds(60);

        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'idempotent_fail.xlsx',
            'file_path' => 'imports/idempotent_fail.xlsx',
            'status' => Import::STATUS_PROCESSING,
            'started_at' => $startedAt,
        ]);

        // First failure call
        $firstResult = $import->markFailed('Initial worker crash reason');
        $this->assertTrue($firstResult);
        $import->refresh();

        $finalizedCompletedAt = $import->completed_at;
        $finalizedDuration = $import->duration_seconds;
        $finalizedReason = $import->failure_reason;

        // Travel 30 seconds into the future
        Carbon::setTestNow(now()->addSeconds(30));

        // Subsequent failure call (e.g. from failed() lifecycle hook after handle() already marked it)
        $secondResult = $import->markFailed('Secondary hook reason');
        $this->assertFalse($secondResult);
        $import->refresh();

        // Metadata must remain identical to the first finalized record
        $this->assertSame($finalizedReason, $import->failure_reason);
        $this->assertEquals($finalizedCompletedAt->toIso8601String(), $import->completed_at->toIso8601String());
        $this->assertSame($finalizedDuration, $import->duration_seconds);

        Carbon::setTestNow(); // Reset Carbon
    }

    /**
     * Requirement 3: Fresh active imports are not marked stale.
     */
    public function test_fresh_active_imports_are_not_marked_stale(): void
    {
        Queue::fake();

        // Active large file import that was updated 2 minutes ago
        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'active_500k.xlsx',
            'file_path' => 'imports/active.xlsx',
            'status' => Import::STATUS_PROCESSING,
        ]);
        DB::table('imports')->where('id', $import->id)->update(['updated_at' => now()->subMinutes(2)]);

        $this->artisan('import:recover-stale --timeout=15')
            ->expectsOutputToContain('No stale imports detected.')
            ->assertSuccessful();

        $import->refresh();
        $this->assertSame(Import::STATUS_PROCESSING, $import->status);
        $this->assertNull($import->completed_at);
        Queue::assertNothingPushed();
    }

    /**
     * Requirement 4: Truly stale imports are detected.
     */
    public function test_truly_stale_imports_are_detected(): void
    {
        Queue::fake();

        // Create spreadsheet file in storage
        $localPath = $this->createXlsxFile([
            ['CUST_001', 'Alice', 'Smith', 'alice@example.com', '+1-555-0101', '1990-01-01', 'Female', 'Engineer', 'TechCorp', 'USA', 'NYC', '1 Broadway', null, '2024-01-01', 'Active'],
        ]);
        $storedPath = Storage::disk('local')->putFileAs('imports', new File($localPath), 'stale_file.xlsx');

        // Import stuck for 30 minutes
        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'stale.xlsx',
            'file_path' => $storedPath,
            'status' => Import::STATUS_PROCESSING,
            'started_at' => now()->subMinutes(35),
        ]);
        DB::table('imports')->where('id', $import->id)->update([
            'updated_at' => now()->subMinutes(30),
            'started_at' => now()->subMinutes(35),
        ]);

        $this->artisan('import:recover-stale --timeout=15')
            ->expectsOutputToContain("Import [{$import->id}] re-dispatched successfully.")
            ->assertSuccessful();

        Queue::assertPushed(ProcessImportJob::class, function ($job) use ($import) {
            return $job->importId === $import->id;
        });

        if (file_exists($localPath)) {
            unlink($localPath);
        }
    }

    /**
     * Requirement 5: Recoverable imports are resumed safely.
     */
    public function test_recoverable_imports_are_resumed_safely(): void
    {
        Queue::fake();

        $localPath = $this->createXlsxFile([
            ['CUST_101', 'Bob', 'Jones', 'bob@example.com', '+1-555-0102', '1985-05-12', 'Male', 'Designer', 'Studio', 'UK', 'London', '22 Baker St', null, '2024-02-01', 'Active'],
        ]);
        $storedPath = Storage::disk('local')->putFileAs('imports', new File($localPath), 'recoverable.xlsx');

        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'recoverable.xlsx',
            'file_path' => $storedPath,
            'status' => Import::STATUS_PROCESSING,
        ]);
        DB::table('imports')->where('id', $import->id)->update(['updated_at' => now()->subMinutes(20)]);

        $this->artisan('import:recover-stale --timeout=15')
            ->assertSuccessful();

        $import->refresh();
        $this->assertSame(Import::STATUS_PROCESSING, $import->status);
        // updated_at should have been refreshed to now
        $this->assertTrue($import->updated_at->isAfter(now()->subMinute()));

        Queue::assertPushed(ProcessImportJob::class, function ($job) use ($import) {
            return $job->importId === $import->id;
        });

        if (file_exists($localPath)) {
            unlink($localPath);
        }
    }

    /**
     * Requirement 6: Non-recoverable imports transition to valid terminal state.
     */
    public function test_non_recoverable_imports_transition_to_valid_terminal_state(): void
    {
        // Import pointing to a file that does NOT exist in storage
        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'deleted_source.xlsx',
            'file_path' => 'imports/non_existent_deleted_file.xlsx',
            'status' => Import::STATUS_PROCESSING,
        ]);
        DB::table('imports')->where('id', $import->id)->update([
            'updated_at' => now()->subMinutes(25),
            'started_at' => now()->subMinutes(30),
        ]);

        $this->artisan('import:recover-stale --timeout=15')
            ->expectsOutputToContain("Import [{$import->id}] marked as Failed (file missing).")
            ->assertSuccessful();

        $import->refresh();
        $this->assertSame(Import::STATUS_FAILED, $import->status);
        $this->assertStringContainsString('spreadsheet file missing from disk', $import->failure_reason);
        $this->assertNotNull($import->completed_at);
        $this->assertNotNull($import->duration_seconds);
        $this->assertGreaterThanOrEqual(1790.0, $import->duration_seconds);
    }

    /**
     * Requirement 7: Concurrent recovery attempts do not dispatch duplicate work.
     */
    public function test_concurrent_recovery_attempts_do_not_dispatch_duplicate_work(): void
    {
        Queue::fake();

        $localPath = $this->createXlsxFile([
            ['CUST_201', 'Charlie', 'Brown', 'charlie@example.com', '+1-555-0201', '1992-03-03', 'Male', 'Dev', 'Co', 'USA', 'Austin', '5 Main', null, '2024-03-01', 'Active'],
        ]);
        $storedPath = Storage::disk('local')->putFileAs('imports', new File($localPath), 'concurrent_test.xlsx');

        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'concurrent.xlsx',
            'file_path' => $storedPath,
            'status' => Import::STATUS_PROCESSING,
        ]);
        DB::table('imports')->where('id', $import->id)->update(['updated_at' => now()->subMinutes(20)]);

        // First run detects and resumes the import
        $this->artisan('import:recover-stale --timeout=15')
            ->assertSuccessful();

        // Exactly 1 job pushed
        Queue::assertPushed(ProcessImportJob::class, 1);

        // Immediate second run (simulating overlapping scheduler trigger)
        // Since updated_at was touched, it is no longer stale
        $this->artisan('import:recover-stale --timeout=15')
            ->expectsOutputToContain('No stale imports detected.')
            ->assertSuccessful();

        // Still exactly 1 job pushed (no duplicate dispatch)
        Queue::assertPushed(ProcessImportJob::class, 1);

        if (file_exists($localPath)) {
            unlink($localPath);
        }
    }

    /**
     * Requirement 8: Reprocessing completed chunks does not double-count saved rows or progress.
     */
    public function test_reprocessing_completed_chunks_does_not_double_count_saved_rows_or_progress(): void
    {
        // 3 unique customer rows in spreadsheet
        $localPath = $this->createXlsxFile([
            ['CUST_301', 'David', 'Clark', 'david@example.com', '+1-555-0301', '1988-08-08', 'Male', 'Doctor', 'Clinic', 'USA', 'Chicago', '10 Oak', null, '2024-01-10', 'Active'],
            ['CUST_302', 'Eva', 'Green', 'eva@example.com', '+1-555-0302', '1991-09-09', 'Female', 'Lawyer', 'Firm', 'USA', 'Chicago', '12 Oak', null, '2024-01-11', 'Active'],
            ['CUST_303', 'Frank', 'Hall', 'frank@example.com', '+1-555-0303', '1987-07-07', 'Male', 'Artist', 'Self', 'USA', 'Chicago', '14 Oak', null, '2024-01-12', 'Active'],
        ]);
        $storedPath = Storage::disk('local')->putFileAs('imports', new File($localPath), 'idempotent_stream.xlsx');

        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'chunk_idempotency.xlsx',
            'file_path' => $storedPath,
            'status' => Import::STATUS_QUEUED,
        ]);

        // Set chunk size to 1 so each row is its own chunk
        config(['excel_import.chunk_size' => 1]);

        // Run full import first time
        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(3, $import->saved_rows);
        $this->assertSame(3, $import->processed_rows);
        $this->assertSame(3, Customer::where('import_id', $import->id)->count());

        // Now simulate a crash recovery re-run of the exact same import job
        // Reset status to Processing to simulate stuck worker
        $import->update(['status' => Import::STATUS_PROCESSING]);

        $recoveryJob = new ProcessImportJob($import->id);
        $recoveryJob->handle($this->parserService, $this->rowValidator);

        $import->refresh();
        // Counter must NOT be double-counted (should remain 3, not 6)
        $this->assertSame(3, $import->saved_rows);
        $this->assertSame(3, $import->processed_rows);
        $this->assertSame(3, Customer::where('import_id', $import->id)->count());

        if (file_exists($localPath)) {
            unlink($localPath);
        }
    }

    /**
     * Requirement 9: Completed imports are never reprocessed.
     */
    public function test_completed_imports_are_never_reprocessed(): void
    {
        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'already_done.xlsx',
            'file_path' => 'imports/already_done.xlsx',
            'status' => Import::STATUS_COMPLETED,
            'completed_at' => now()->subHours(2),
            'saved_rows' => 100,
        ]);
        DB::table('imports')->where('id', $import->id)->update(['updated_at' => now()->subHours(2)]);

        // Stale command must ignore it
        $this->artisan('import:recover-stale --timeout=15')
            ->assertSuccessful();

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);

        // Even if ProcessImportJob is invoked on completed import, it exits immediately
        $job = new ProcessImportJob($import->id);
        $job->handle($this->parserService, $this->rowValidator);

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(100, $import->saved_rows);
    }

    /**
     * Requirement 10: Queue retry exhaustion follows expected failure path with accurate duration.
     */
    public function test_queue_retry_exhaustion_follows_expected_failure_path(): void
    {
        $storedPath = 'imports/retry_exhaust.xlsx';
        Storage::disk('local')->put($storedPath, 'dummy binary content');

        $startedAt = now()->subSeconds(25);

        $import = Import::create([
            'user_id' => $this->user->id,
            'filename' => 'exhaust.xlsx',
            'file_path' => $storedPath,
            'status' => Import::STATUS_PROCESSING,
            'started_at' => $startedAt,
        ]);

        $job = new ProcessImportJob($import->id);

        // Mock queue job on attempt 3 of 3 (final attempt)
        $mockQueueJob = Mockery::mock(Job::class);
        $mockQueueJob->shouldReceive('attempts')->andReturn(3);
        $job->setJob($mockQueueJob);

        $mockParser = Mockery::mock(ExcelParserService::class);
        $mockParser->shouldReceive('detectTotalRows')->andReturn(null);
        $mockParser->shouldReceive('validateHeaders')->andThrow(new RuntimeException('Fatal parse error on DB host=127.0.0.1 user=excel_user password=secret123'));

        try {
            $job->handle($mockParser, $this->rowValidator);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Fatal parse error', $e->getMessage());
        }

        $import->refresh();

        // Terminal attempt marks import as Failed with sanitized error and accurate duration
        $this->assertSame(Import::STATUS_FAILED, $import->status);
        $this->assertNotNull($import->completed_at);
        $this->assertNotNull($import->duration_seconds);
        $this->assertGreaterThanOrEqual(24.0, $import->duration_seconds);
        $this->assertStringNotContainsString('password=secret123', $import->failure_reason);
        $this->assertStringContainsString('[redacted]', $import->failure_reason);
    }
}
