<?php

namespace Tests;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

trait CreatesXlsxFiles
{
    /**
     * Create a temporary .xlsx file with specified rows.
     *
     * @param  array<int, array>  $rows
     * @param  array<int, string>|null  $customHeaders
     * @return string Path to temporary file
     */
    protected function createXlsxFile(array $rows, ?array $customHeaders = null): string
    {
        $headers = $customHeaders ?? [
            'Customer Id',
            'First Name',
            'Last Name',
            'Email',
            'Phone Number',
            'Date of Birth (DOB)',
            'Gender',
            'Profession',
            'Company Name',
            'Country',
            'City',
            'Address',
            'Social Media URL',
            'Registration Date',
            'Status',
        ];

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_xlsx_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($tmpFile);
        $writer->addRow(Row::fromValues($headers));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $tmpFile;
    }
}
