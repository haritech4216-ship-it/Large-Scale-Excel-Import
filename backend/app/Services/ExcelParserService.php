<?php

namespace App\Services;

use Exception;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

class ExcelParserService
{
    /**
     * Standard column headers for the customer import template.
     */
    public const TEMPLATE_HEADERS = [
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
     * Inspect XLSX package metadata for exact data row count without parsing the full sheet.
     * Returns null if the package or worksheet dimension cannot be reliably determined.
     */
    public function detectTotalRows(string $filePath): ?int
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return null;
        }

        $totalRows = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#', $name)) {
                $fp = $zip->getStream($name);
                if ($fp) {
                    $buffer = fread($fp, 4096);
                    fclose($fp);

                    if (preg_match('/<dimension[^>]*ref=["\']([A-Za-z0-9:]+)["\']/', $buffer, $matches)) {
                        $ref = $matches[1];
                        if (str_contains($ref, ':')) {
                            [, $endCell] = explode(':', $ref, 2);
                            if (preg_match('/[A-Za-z]+([0-9]+)/', $endCell, $rowMatch)) {
                                $lastRow = (int) $rowMatch[1];
                                $totalRows = max(0, $lastRow - 1);
                                break;
                            }
                        }
                    }
                }
            }
        }

        $zip->close();

        return $totalRows;
    }

    /**
     * Get a configured OpenSpout XLSX Reader.
     */
    public function createReader(): Reader
    {
        return new Reader;
    }

    /**
     * Return 20 realistic, valid sample customer data rows matching template headers.
     *
     * @return array<int, array<int, string>>
     */
    public function getSampleRows(): array
    {
        return [
            ['CUST-1001', 'James', 'Wilson', 'james.wilson@example.com', '+1-202-555-0143', '1985-04-12', 'Male', 'Software Engineer', 'Apex Innovations', 'United States', 'Austin', '100 Congress Ave', 'https://linkedin.com/in/james-wilson-sample', '2023-01-15', 'Active'],
            ['CUST-1002', 'Emma', 'Taylor', 'emma.taylor@example.com', '+44-20-7946-0912', '1990-08-23', 'Female', 'Product Manager', 'Bright Horizons Ltd', 'United Kingdom', 'London', '25 Baker Street', 'https://linkedin.com/in/emma-taylor-sample', '2023-02-01', 'Active'],
            ['CUST-1003', 'Liam', 'Nguyen', 'liam.nguyen@example.com', '+61-2-9374-4000', '1988-11-05', 'Male', 'Data Scientist', 'Pacific Data Labs', 'Australia', 'Sydney', '48 Martin Place', 'https://twitter.com/liam_nguyen_sample', '2023-02-14', 'Active'],
            ['CUST-1004', 'Sophia', 'Schmidt', 'sophia.schmidt@example.com', '+49-30-2888-00', '1992-03-30', 'Female', 'Financial Analyst', 'Vanguard Equity', 'Germany', 'Berlin', 'Friedrichstrasse 43', 'https://linkedin.com/in/sophia-schmidt-sample', '2023-03-10', 'Inactive'],
            ['CUST-1005', 'Noah', 'Dubois', 'noah.dubois@example.com', '+33-1-42-68-55-00', '1983-07-19', 'Male', 'DevOps Architect', 'CloudScale Solutions', 'France', 'Paris', '12 Rue de Rivoli', 'https://github.com/noah-dubois-sample', '2023-03-22', 'Active'],
            ['CUST-1006', 'Olivia', 'Santos', 'olivia.santos@example.com', '+55-11-3040-2000', '1995-12-14', 'Female', 'UX Designer', 'Creativa Digital', 'Brazil', 'São Paulo', 'Av. Paulista 1000', 'https://linkedin.com/in/olivia-santos-sample', '2023-04-05', 'Active'],
            ['CUST-1007', 'Lucas', 'Rossi', 'lucas.rossi@example.com', '+39-06-6987-1234', '1987-09-08', 'Male', 'Systems Administrator', 'Nexus Systems', 'Italy', 'Rome', 'Via del Corso 18', 'https://linkedin.com/in/lucas-rossi-sample', '2023-04-18', 'Active'],
            ['CUST-1008', 'Mia', 'Tanaka', 'mia.tanaka@example.com', '+81-3-5555-0182', '1991-01-25', 'Female', 'Marketing Director', 'Sunrise Media', 'Japan', 'Tokyo', '6-10-1 Roppongi', 'https://twitter.com/mia_tanaka_sample', '2023-05-02', 'Inactive'],
            ['CUST-1009', 'Alexander', 'Kovacs', 'alexander.kovacs@example.com', '+1-416-555-0199', '1984-06-17', 'Male', 'Database Administrator', 'Great Lakes Analytics', 'Canada', 'Toronto', '100 King St West', 'https://linkedin.com/in/alex-kovacs-sample', '2023-05-20', 'Active'],
            ['CUST-1010', 'Ava', 'Johansson', 'ava.johansson@example.com', '+46-8-123-4567', '1993-10-11', 'Female', 'Cybersecurity Specialist', 'Nordic Shield AB', 'Sweden', 'Stockholm', 'Kungsgatan 12', 'https://linkedin.com/in/ava-johansson-sample', '2023-06-01', 'Active'],
            ['CUST-1011', 'Ethan', 'Chen', 'ethan.chen@example.com', '+65-6789-0123', '1986-02-28', 'Male', 'AI Research Scientist', 'NeuralCore Labs', 'Singapore', 'Singapore', '1 Fusionopolis Way', 'https://github.com/ethan-chen-sample', '2023-06-15', 'Active'],
            ['CUST-1012', 'Isabella', 'Garcia', 'isabella.garcia@example.com', '+34-91-555-0144', '1994-05-09', 'Female', 'Full Stack Developer', 'Iberia Tech Group', 'Spain', 'Madrid', 'Paseo de la Castellana 80', 'https://linkedin.com/in/isabella-garcia-sample', '2023-07-04', 'Active'],
            ['CUST-1013', 'Mason', 'van der Berg', 'mason.vandenberg@example.com', '+31-20-555-0122', '1989-11-18', 'Male', 'Solutions Architect', 'Delta Cloud Services', 'Netherlands', 'Amsterdam', 'Keizersgracht 421', 'https://linkedin.com/in/mason-vandenberg-sample', '2023-07-19', 'Inactive'],
            ['CUST-1014', 'Charlotte', 'Kim', 'charlotte.kim@example.com', '+82-2-555-0188', '1992-09-03', 'Female', 'Product Operations Manager', 'Han River Ventures', 'South Korea', 'Seoul', 'Teheran-ro 152', 'https://linkedin.com/in/charlotte-kim-sample', '2023-08-08', 'Active'],
            ['CUST-1015', 'Benjamin', 'Patel', 'benjamin.patel@example.com', '+91-22-5555-0166', '1987-03-21', 'Male', 'Cloud Infrastructure Engineer', 'Apex Enterprise Solutions', 'India', 'Mumbai', 'Bandra Kurla Complex', 'https://github.com/benjamin-patel-sample', '2023-08-25', 'Active'],
            ['CUST-1016', 'Amelia', 'Fischer', 'amelia.fischer@example.com', '+41-44-555-0111', '1990-12-07', 'Female', 'Risk Management Consultant', 'Helvetia Advisory', 'Switzerland', 'Zurich', 'Bahnhofstrasse 45', 'https://linkedin.com/in/amelia-fischer-sample', '2023-09-12', 'Active'],
            ['CUST-1017', 'Daniel', 'Silva', 'daniel.silva@example.com', '+351-21-555-0177', '1985-08-14', 'Male', 'Network Operations Specialist', 'Lusitania Networks', 'Portugal', 'Lisbon', 'Avenida da Liberdade 110', 'https://linkedin.com/in/daniel-silva-sample', '2023-09-28', 'Active'],
            ['CUST-1018', 'Harper', 'O\'Connor', 'harper.oconnor@example.com', '+353-1-496-0123', '1993-04-16', 'Female', 'Content Strategist', 'Emerald Digital Media', 'Ireland', 'Dublin', 'Grand Canal Dock', 'https://twitter.com/harper_oconnor_sample', '2023-10-10', 'Inactive'],
            ['CUST-1019', 'Elijah', 'Müller', 'elijah.mueller@example.com', '+43-1-555-0133', '1988-10-29', 'Male', 'Machine Learning Engineer', 'Danube Cognitive Computing', 'Austria', 'Vienna', 'Mariahilfer Strasse 76', 'https://linkedin.com/in/elijah-mueller-sample', '2023-10-24', 'Active'],
            ['CUST-1020', 'Evelyn', 'Larsson', 'evelyn.larsson@example.com', '+47-22-55-01-44', '1991-07-02', 'Female', 'Quality Assurance Lead', 'Fjord Quality Systems', 'Norway', 'Oslo', 'Karl Johans gate 22', 'https://linkedin.com/in/evelyn-larsson-sample', '2023-11-05', 'Active'],
        ];
    }

    /**
     * Generate an Excel spreadsheet template with headers and 20 sample customer records.
     *
     * @param  string  $outputPath Path where the generated .xlsx file should be written.
     * @return string The output file path.
     */
    public function generateSampleTemplate(string $outputPath): string
    {
        $writer = new Writer;
        $writer->openToFile($outputPath);
        $writer->addRow(Row::fromValues(self::TEMPLATE_HEADERS));

        foreach ($this->getSampleRows() as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $outputPath;
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
