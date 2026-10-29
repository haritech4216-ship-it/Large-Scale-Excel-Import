<?php

namespace Tests\Unit;

use App\Services\ExcelParserService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ExcelParserServiceTest extends TestCase
{
    protected ExcelParserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExcelParserService;
    }

    public function test_detect_total_rows_returns_null_for_non_existent_file(): void
    {
        $result = $this->service->detectTotalRows('/path/to/non/existent/file.xlsx');
        $this->assertNull($result);
    }

    public function test_detect_total_rows_returns_null_for_non_zip_file(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_txt_');
        file_put_contents($tmp, 'Not a zip file');

        $result = $this->service->detectTotalRows($tmp);
        unlink($tmp);

        $this->assertNull($result);
    }

    public function test_detect_total_rows_extracts_correct_count_from_xlsx_dimension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_xlsx_').'.xlsx';
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $sheetXml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:O501"/><sheetData></sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $result = $this->service->detectTotalRows($tmp);
        unlink($tmp);

        // 501 rows total, minus 1 header row = 500 data rows
        $this->assertSame(500, $result);
    }

    public function test_detect_total_rows_handles_single_header_row(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_xlsx_').'.xlsx';
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $sheetXml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:O1"/><sheetData></sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $result = $this->service->detectTotalRows($tmp);
        unlink($tmp);

        // 1 row total (only header), 0 data rows
        $this->assertSame(0, $result);
    }

    public function test_sample_template_contains_twenty_valid_rows(): void
    {
        $rows = $this->service->getSampleRows();
        $this->assertCount(20, $rows);

        $validator = new \App\Services\CustomerRowValidator;
        $customerIds = [];
        $emails = [];

        foreach ($rows as $index => $row) {
            $this->assertCount(15, $row, "Row at index {$index} must have 15 columns.");

            $rowMap = [
                'customer_id' => $row[0],
                'first_name' => $row[1],
                'last_name' => $row[2],
                'email' => $row[3],
                'phone_number' => $row[4],
                'date_of_birth' => $row[5],
                'gender' => $row[6],
                'profession' => $row[7],
                'company_name' => $row[8],
                'country' => $row[9],
                'city' => $row[10],
                'address' => $row[11],
                'social_media_url' => $row[12],
                'registration_date' => $row[13],
                'status' => $row[14],
            ];

            $valResult = $validator->validate($rowMap);
            $this->assertTrue($valResult['valid'], "Row {$index} failed validation: " . ($valResult['error'] ?? ''));

            $customerIds[] = $row[0];
            $emails[] = strtolower($row[3]);
        }

        // Verify uniqueness
        $this->assertCount(20, array_unique($customerIds), 'All customer IDs must be unique.');
        $this->assertCount(20, array_unique($emails), 'All emails must be unique.');
    }

    public function test_generate_sample_template_writes_valid_xlsx_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sample_test_').'.xlsx';
        $outputPath = $this->service->generateSampleTemplate($tmpFile);

        $this->assertFileExists($outputPath);

        $headerMap = $this->service->validateHeaders($outputPath);
        $this->assertCount(15, $headerMap);

        $totalRows = $this->service->detectTotalRows($outputPath);
        $this->assertSame(20, $totalRows);

        unlink($outputPath);
    }
}
