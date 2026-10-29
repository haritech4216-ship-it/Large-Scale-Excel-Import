<?php

namespace App\Services;

use Exception;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Reader;

class ExcelParserService
{
    /**
     * Map of standard field keys to header aliases (all lowercased and stripped of special characters).
     */
    protected const HEADER_MAP = [
        'customer_id' => ['customer id', 'customer_id', 'customerid', 'cust id'],
        'first_name' => ['first name', 'first_name', 'firstname', 'first'],
        'last_name' => ['last name', 'last_name', 'lastname', 'last'],
        'email' => ['email', 'email address', 'e-mail'],
        'phone_number' => ['phone number', 'phone', 'phone_number', 'phonenumber', 'mobile'],
        'date_of_birth' => ['date of birth (dob)', 'date of birth', 'dob', 'date_of_birth', 'birth date'],
        'gender' => ['gender', 'sex'],
        'profession' => ['profession', 'occupation', 'job title'],
        'company_name' => ['company name', 'company', 'company_name', 'organization'],
        'country' => ['country'],
        'city' => ['city'],
        'address' => ['address'],
        'social_media_url' => ['social media url', 'social_media_url', 'social url', 'social_url', 'profile url'],
        'registration_date' => ['registration date', 'registration_date', 'registered date', 'reg date'],
        'status' => ['status'],
    ];

    /**
     * Required standard fields.
     */
    protected const REQUIRED_FIELDS = [
        'customer_id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'date_of_birth',
        'profession',
        'country',
        'city',
        'registration_date',
        'status',
    ];

    /**
     * Validate the spreadsheet structure and extract column index mappings.
     *
     * @return array<string, int> Mapping of field name => column index
     *
     * @throws InvalidArgumentException
     */
    public function validateHeaders(string $filePath): array
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException('Uploaded file cannot be read.');
        }

        $reader = new Reader;

        try {
            $reader->open($filePath);
        } catch (Exception $e) {
            throw new InvalidArgumentException('Unable to open file as an Excel workbook: '.$e->getMessage());
        }

        $headerRow = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $headerRow = $row;
                    break 2; // Stop after first row of first sheet
                }
            }
        } catch (Exception $e) {
            $reader->close();
            throw new InvalidArgumentException('Error reading Excel headers: '.$e->getMessage());
        }

        $reader->close();

        if ($headerRow === null) {
            throw new InvalidArgumentException('Excel workbook is empty or has no header row.');
        }

        $cells = $headerRow->toArray();
        if (empty($cells)) {
            throw new InvalidArgumentException('Excel header row contains no cells.');
        }

        $columnMap = [];
        foreach ($cells as $index => $cellValue) {
            $rawHeader = trim((string) $cellValue);
            $normalized = $this->normalizeHeaderString($rawHeader);

            foreach (self::HEADER_MAP as $field => $aliases) {
                if (in_array($normalized, $aliases, true) && ! isset($columnMap[$field])) {
                    $columnMap[$field] = $index;
                    break;
                }
            }
        }

        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! isset($columnMap[$field])) {
                $missing[] = ucwords(str_replace('_', ' ', $field));
            }
        }

        if (! empty($missing)) {
            throw new InvalidArgumentException('Missing required header(s): '.implode(', ', $missing).'.');
        }

        return $columnMap;
    }

    /**
     * Get a configured OpenSpout XLSX Reader.
     */
    public function createReader(): Reader
    {
        return new Reader;
    }

    /**
     * Normalize a header string for comparison.
     */
    public function normalizeHeaderString(string $header): string
    {
        $cleaned = strtolower(trim($header));

        return preg_replace('/\s+/', ' ', $cleaned);
    }
}
