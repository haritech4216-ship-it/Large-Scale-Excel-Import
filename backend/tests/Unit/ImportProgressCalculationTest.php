<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\ImportController;
use App\Models\Import;
use App\Services\ExcelParserService;
use Tests\TestCase;

class ImportProgressCalculationTest extends TestCase
{
    protected ImportController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ImportController(new ExcelParserService());
    }

    /**
     * Test 1: A multi-chunk file where only the first chunk has been processed.
     * Database import stage must not jump to 99.9% or 100% after chunk 1.
     */
    public function test_multi_chunk_first_chunk_processed_does_not_jump_import_stage_to_completion(): void
    {
        $import = new Import([
            'status' => Import::STATUS_PROCESSING,
            'total_rows' => 1000,
            'processed_rows' => 500,
            'validated_rows' => 500,
            'valid_rows' => 480,
            'invalid_rows' => 10,
            'duplicate_rows' => 10,
            'saved_rows' => 480,
            'rejected_rows' => 20,
        ]);

        $progress = $this->controller->calculateProgress($import);

        $this->assertTrue($progress['total_rows_known']);
        $this->assertSame(50.0, $progress['progress_percentage']);
        $this->assertSame(50.0, $progress['stages']['validation']['percentage']);
        $this->assertFalse($progress['stages']['validation']['indeterminate']);

        // Stage 3 must not jump to 99.9% or 100% after chunk 1; it is indeterminate because validation has not completed
        $this->assertTrue($progress['stages']['import']['indeterminate']);
        $this->assertSame(0.0, $progress['stages']['import']['percentage']);
        $this->assertSame(480, $progress['stages']['import']['saved_rows']);
        $this->assertSame(480, $progress['stages']['import']['eligible_rows']);
    }

    /**
     * Test 2: A partially validated file where saved_rows equals currently validated valid rows
     * but more rows remain unread.
     */
    public function test_partially_validated_where_saved_rows_equals_valid_rows_but_more_rows_unread(): void
    {
        $import = new Import([
            'status' => Import::STATUS_PROCESSING,
            'total_rows' => 10000,
            'processed_rows' => 1000,
            'validated_rows' => 1000,
            'valid_rows' => 950,
            'invalid_rows' => 30,
            'duplicate_rows' => 20,
            'saved_rows' => 950,
            'rejected_rows' => 50,
        ]);

        $progress = $this->controller->calculateProgress($import);

        // Validation is 10%
        $this->assertSame(10.0, $progress['stages']['validation']['percentage']);
        $this->assertFalse($progress['stages']['validation']['indeterminate']);

        // Stage 3 has saved_rows == valid_rows (950 == 950), but 9000 rows remain unread.
        // Stage 3 MUST NOT show 99.9% or 100%; it must accurately represent that total eligible rows are not yet known
        $this->assertTrue($progress['stages']['import']['indeterminate']);
        $this->assertSame(0.0, $progress['stages']['import']['percentage']);
        $this->assertNotEquals(99.9, $progress['stages']['import']['percentage']);
        $this->assertNotEquals(100.0, $progress['stages']['import']['percentage']);
    }

    /**
     * Test 3: An import with invalid and duplicate rows completed with errors.
     */
    public function test_completed_import_with_invalid_and_duplicate_rows(): void
    {
        $import = new Import([
            'status' => Import::STATUS_COMPLETED_WITH_ERRORS,
            'total_rows' => 1000,
            'processed_rows' => 1000,
            'validated_rows' => 1000,
            'valid_rows' => 800,
            'invalid_rows' => 120,
            'duplicate_rows' => 80,
            'saved_rows' => 800,
            'rejected_rows' => 200,
        ]);

        $progress = $this->controller->calculateProgress($import);

        $this->assertTrue($progress['total_rows_known']);
        $this->assertSame(100.0, $progress['progress_percentage']);

        // Validation stage
        $this->assertSame('completed', $progress['stages']['validation']['status']);
        $this->assertSame(100.0, $progress['stages']['validation']['percentage']);
        $this->assertSame(800, $progress['stages']['validation']['valid_rows']);
        $this->assertSame(120, $progress['stages']['validation']['invalid_rows']);
        $this->assertSame(80, $progress['stages']['validation']['duplicate_rows']);
        $this->assertFalse($progress['stages']['validation']['indeterminate']);

        // Import stage
        $this->assertSame('completed', $progress['stages']['import']['status']);
        $this->assertSame(100.0, $progress['stages']['import']['percentage']);
        $this->assertSame(800, $progress['stages']['import']['saved_rows']);
        $this->assertSame(800, $progress['stages']['import']['eligible_rows']);
        $this->assertFalse($progress['stages']['import']['indeterminate']);
    }

    /**
     * Test 4: Unknown total rows remains indeterminate.
     */
    public function test_unknown_total_rows_remains_indeterminate(): void
    {
        $import = new Import([
            'status' => Import::STATUS_PROCESSING,
            'total_rows' => 0,
            'processed_rows' => 800,
            'validated_rows' => 800,
            'valid_rows' => 750,
            'invalid_rows' => 30,
            'duplicate_rows' => 20,
            'saved_rows' => 750,
            'rejected_rows' => 50,
        ]);

        $progress = $this->controller->calculateProgress($import);

        $this->assertFalse($progress['total_rows_known']);
        $this->assertSame(0.0, $progress['progress_percentage']);

        // Validation stage must be indeterminate
        $this->assertTrue($progress['stages']['validation']['indeterminate']);
        $this->assertSame(0.0, $progress['stages']['validation']['percentage']);

        // Import stage must be indeterminate
        $this->assertTrue($progress['stages']['import']['indeterminate']);
        $this->assertSame(0.0, $progress['stages']['import']['percentage']);
    }

    /**
     * Test 5: A genuine zero-data-row file represented as zero rows, not as an unknown total.
     */
    public function test_genuine_zero_data_row_file_represented_as_zero_rows(): void
    {
        $import = new Import([
            'status' => Import::STATUS_COMPLETED,
            'total_rows' => 0,
            'processed_rows' => 0,
            'validated_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'saved_rows' => 0,
            'rejected_rows' => 0,
        ]);

        $progress = $this->controller->calculateProgress($import);

        // Must be represented as known zero rows, not unknown
        $this->assertTrue($progress['total_rows_known']);
        $this->assertSame(100.0, $progress['progress_percentage']);
        $this->assertSame(100.0, $progress['stages']['validation']['percentage']);
        $this->assertFalse($progress['stages']['validation']['indeterminate']);
        $this->assertSame(0, $progress['stages']['validation']['total_rows']);

        // Stage 3: 0 eligible rows, 0 saved rows -> 0.0% saved, never claiming 100% saved
        $this->assertSame('completed', $progress['stages']['import']['status']);
        $this->assertSame(0.0, $progress['stages']['import']['percentage']);
        $this->assertFalse($progress['stages']['import']['indeterminate']);
        $this->assertSame(0, $progress['stages']['import']['saved_rows']);
        $this->assertSame(0, $progress['stages']['import']['eligible_rows']);
    }

    /**
     * Test 6: An active import never shows 100%.
     */
    public function test_active_import_never_shows_100_percent(): void
    {
        // Even when all rows are validated and saved, while still in Processing state, it must cap at 99.9%
        $import = new Import([
            'status' => Import::STATUS_PROCESSING,
            'total_rows' => 1000,
            'processed_rows' => 1000,
            'validated_rows' => 1000,
            'valid_rows' => 1000,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'saved_rows' => 1000,
            'rejected_rows' => 0,
        ]);

        $progress = $this->controller->calculateProgress($import);

        $this->assertSame(99.9, $progress['progress_percentage']);
        $this->assertLessThan(100.0, $progress['progress_percentage']);

        $this->assertSame(99.9, $progress['stages']['validation']['percentage']);
        $this->assertLessThan(100.0, $progress['stages']['validation']['percentage']);

        $this->assertSame(99.9, $progress['stages']['import']['percentage']);
        $this->assertLessThan(100.0, $progress['stages']['import']['percentage']);
    }

    /**
     * Test 7: A successfully completed import reaching 100%.
     */
    public function test_successfully_completed_import_reaches_100_percent(): void
    {
        $import = new Import([
            'status' => Import::STATUS_COMPLETED,
            'total_rows' => 1000,
            'processed_rows' => 1000,
            'validated_rows' => 1000,
            'valid_rows' => 1000,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'saved_rows' => 1000,
            'rejected_rows' => 0,
        ]);

        $progress = $this->controller->calculateProgress($import);

        $this->assertSame(100.0, $progress['progress_percentage']);
        $this->assertSame(100.0, $progress['stages']['validation']['percentage']);
        $this->assertFalse($progress['stages']['validation']['indeterminate']);
        $this->assertSame(100.0, $progress['stages']['import']['percentage']);
        $this->assertFalse($progress['stages']['import']['indeterminate']);
        $this->assertSame('completed', $progress['stages']['import']['status']);
    }

    /**
     * Test 8: Queued import state initialization.
     */
    public function test_queued_import_state_initialization(): void
    {
        $import = new Import([
            'status' => Import::STATUS_QUEUED,
            'total_rows' => 500,
            'processed_rows' => 0,
            'validated_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'saved_rows' => 0,
            'rejected_rows' => 0,
        ]);

        $progress = $this->controller->calculateProgress($import);

        $this->assertSame(0.0, $progress['progress_percentage']);
        $this->assertSame('queued', $progress['stages']['validation']['status']);
        $this->assertSame(0.0, $progress['stages']['validation']['percentage']);
        $this->assertSame('queued', $progress['stages']['import']['status']);
        $this->assertSame(0.0, $progress['stages']['import']['percentage']);
        $this->assertFalse($progress['stages']['import']['indeterminate']);
    }

    /**
     * Test 9: Failed import state representation preserves progress achieved prior to failure.
     */
    public function test_failed_import_state(): void
    {
        $import = new Import([
            'status' => Import::STATUS_FAILED,
            'total_rows' => 500,
            'processed_rows' => 200,
            'validated_rows' => 200,
            'valid_rows' => 190,
            'invalid_rows' => 10,
            'duplicate_rows' => 0,
            'saved_rows' => 190,
            'rejected_rows' => 10,
        ]);

        $progress = $this->controller->calculateProgress($import);

        // Preserves 200 / 500 = 40.0% validation progress achieved prior to failure
        $this->assertSame(40.0, $progress['progress_percentage']);
        $this->assertSame('failed', $progress['stages']['validation']['status']);
        $this->assertSame(40.0, $progress['stages']['validation']['percentage']);
        $this->assertSame('failed', $progress['stages']['import']['status']);
        $this->assertSame(0.0, $progress['stages']['import']['percentage']);
        $this->assertFalse($progress['stages']['import']['indeterminate']);
    }

    /**
     * Test 10: All 20 rows are duplicates, zero eligible, zero saved.
     * Must not report 100% saved in stage 3.
     */
    public function test_all_twenty_rows_duplicates_zero_eligible_zero_saved(): void
    {
        $import = new Import([
            'status' => Import::STATUS_COMPLETED_WITH_ERRORS,
            'total_rows' => 20,
            'processed_rows' => 20,
            'validated_rows' => 20,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 20,
            'saved_rows' => 0,
            'rejected_rows' => 20,
        ]);

        $progress = $this->controller->calculateProgress($import);

        $this->assertTrue($progress['total_rows_known']);
        $this->assertSame(100.0, $progress['progress_percentage']);

        // Stage 1: Upload completed
        $this->assertSame('completed', $progress['stages']['upload']['status']);
        $this->assertSame(100.0, $progress['stages']['upload']['percentage']);

        // Stage 2: Validation 100% completed
        $this->assertSame('completed', $progress['stages']['validation']['status']);
        $this->assertSame(100.0, $progress['stages']['validation']['percentage']);
        $this->assertSame(20, $progress['stages']['validation']['checked_rows']);
        $this->assertSame(0, $progress['stages']['validation']['valid_rows']);
        $this->assertSame(20, $progress['stages']['validation']['duplicate_rows']);
        $this->assertSame(0, $progress['stages']['validation']['invalid_rows']);

        // Stage 3: Database import must accurately report 0% saved with 0 eligible rows
        $this->assertSame('completed', $progress['stages']['import']['status']);
        $this->assertSame(0.0, $progress['stages']['import']['percentage']);
        $this->assertSame(0, $progress['stages']['import']['saved_rows']);
        $this->assertSame(0, $progress['stages']['import']['eligible_rows']);
        $this->assertFalse($progress['stages']['import']['indeterminate']);
    }

    /**
     * Test 11: All 20 rows are invalid, zero eligible, zero saved.
     */
    public function test_all_twenty_rows_invalid_zero_eligible_zero_saved(): void
    {
        $import = new Import([
            'status' => Import::STATUS_COMPLETED_WITH_ERRORS,
            'total_rows' => 20,
            'processed_rows' => 20,
            'validated_rows' => 20,
            'valid_rows' => 0,
            'invalid_rows' => 20,
            'duplicate_rows' => 0,
            'saved_rows' => 0,
            'rejected_rows' => 20,
        ]);

        $progress = $this->controller->calculateProgress($import);

        $this->assertTrue($progress['total_rows_known']);
        $this->assertSame(100.0, $progress['progress_percentage']);

        // Stage 2: Validation 100% completed
        $this->assertSame('completed', $progress['stages']['validation']['status']);
        $this->assertSame(100.0, $progress['stages']['validation']['percentage']);
        $this->assertSame(20, $progress['stages']['validation']['checked_rows']);
        $this->assertSame(20, $progress['stages']['validation']['invalid_rows']);
        $this->assertSame(0, $progress['stages']['validation']['valid_rows']);

        // Stage 3: Database import must accurately report 0% saved
        $this->assertSame('completed', $progress['stages']['import']['status']);
        $this->assertSame(0.0, $progress['stages']['import']['percentage']);
        $this->assertSame(0, $progress['stages']['import']['saved_rows']);
        $this->assertSame(0, $progress['stages']['import']['eligible_rows']);
        $this->assertFalse($progress['stages']['import']['indeterminate']);
    }

    /**
     * Test 12: 20 eligible rows, 10 saved, import still processing.
     */
    public function test_twenty_eligible_rows_ten_saved_import_still_processing(): void
    {
        $import = new Import([
            'status' => Import::STATUS_PROCESSING,
            'total_rows' => 20,
            'processed_rows' => 20,
            'validated_rows' => 20,
            'valid_rows' => 20,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'saved_rows' => 10,
            'rejected_rows' => 0,
        ]);

        $progress = $this->controller->calculateProgress($import);

        $this->assertTrue($progress['total_rows_known']);
        $this->assertSame(99.9, $progress['progress_percentage']);
        $this->assertSame(99.9, $progress['stages']['validation']['percentage']);

        // Stage 3: 10 / 20 = 50.0%
        $this->assertSame('processing', $progress['stages']['import']['status']);
        $this->assertSame(50.0, $progress['stages']['import']['percentage']);
        $this->assertSame(10, $progress['stages']['import']['saved_rows']);
        $this->assertSame(20, $progress['stages']['import']['eligible_rows']);
        $this->assertFalse($progress['stages']['import']['indeterminate']);
    }

    /**
     * Test 13: 20 eligible rows, all saved successfully.
     */
    public function test_twenty_eligible_rows_all_saved_successfully(): void
    {
        $import = new Import([
            'status' => Import::STATUS_COMPLETED,
            'total_rows' => 20,
            'processed_rows' => 20,
            'validated_rows' => 20,
            'valid_rows' => 20,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'saved_rows' => 20,
            'rejected_rows' => 0,
        ]);

        $progress = $this->controller->calculateProgress($import);

        $this->assertTrue($progress['total_rows_known']);
        $this->assertSame(100.0, $progress['progress_percentage']);
        $this->assertSame('completed', $progress['stages']['validation']['status']);
        $this->assertSame(100.0, $progress['stages']['validation']['percentage']);

        $this->assertSame('completed', $progress['stages']['import']['status']);
        $this->assertSame(100.0, $progress['stages']['import']['percentage']);
        $this->assertSame(20, $progress['stages']['import']['saved_rows']);
        $this->assertSame(20, $progress['stages']['import']['eligible_rows']);
        $this->assertFalse($progress['stages']['import']['indeterminate']);
    }

    /**
     * Test 14: Partial save followed by failure.
     */
    public function test_partial_save_followed_by_failure(): void
    {
        $import = new Import([
            'status' => Import::STATUS_FAILED,
            'total_rows' => 20,
            'processed_rows' => 20,
            'validated_rows' => 20,
            'valid_rows' => 20,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'saved_rows' => 10,
            'rejected_rows' => 0,
        ]);

        $progress = $this->controller->calculateProgress($import);

        // Validation stage reflects failure
        $this->assertSame('failed', $progress['stages']['validation']['status']);
        $this->assertSame(100.0, $progress['stages']['validation']['percentage']);

        // Stage 3 preserves actual persistence progress (10 / 20 = 50.0%) with failed status
        $this->assertSame('failed', $progress['stages']['import']['status']);
        $this->assertSame(50.0, $progress['stages']['import']['percentage']);
        $this->assertSame(10, $progress['stages']['import']['saved_rows']);
        $this->assertSame(20, $progress['stages']['import']['eligible_rows']);
        $this->assertFalse($progress['stages']['import']['indeterminate']);
    }
}
