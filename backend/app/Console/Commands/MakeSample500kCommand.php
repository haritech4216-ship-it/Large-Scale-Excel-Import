<?php

namespace App\Console\Commands;

use App\Services\ExcelParserService;
use Illuminate\Console\Command;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class MakeSample500kCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:sample-500k
                            {--output= : Custom path to write the generated .xlsx file}
                            {--count=500000 : Number of customer records to generate (default 500,000)}
                            {--prefix=BENCH- : Unique Customer ID prefix (default BENCH-)}
                            {--invalid=0 : Number of intentional invalid rows to inject}
                            {--duplicates=0 : Number of intentional duplicate Customer IDs to inject}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a deterministic 500,000-row Excel spreadsheet for performance benchmarking using streaming';

    /**
     * Realistic data pools for deterministic data generation.
     */
    protected const FIRST_NAMES = [
        'James', 'Emma', 'Liam', 'Sophia', 'Noah', 'Olivia', 'Lucas', 'Mia',
        'Alexander', 'Ava', 'Ethan', 'Isabella', 'Mason', 'Charlotte', 'Benjamin',
        'Amelia', 'Daniel', 'Harper', 'Elijah', 'Evelyn', 'Oliver', 'Abigail',
        'William', 'Emily', 'Henry', 'Elizabeth', 'Sebastian', 'Sofia', 'Jack', 'Avery',
    ];

    protected const LAST_NAMES = [
        'Wilson', 'Taylor', 'Nguyen', 'Schmidt', 'Dubois', 'Santos', 'Rossi', 'Tanaka',
        'Kovacs', 'Johansson', 'Chen', 'Garcia', 'van der Berg', 'Kim', 'Patel',
        'Fischer', 'Silva', 'O\'Connor', 'Müller', 'Larsson', 'Andersson', 'Moreau',
        'Novak', 'Alvarez', 'Jansen', 'Costa', 'Sato', 'Watanabe', 'Cohen', 'Becker',
    ];

    protected const PROFESSIONS = [
        'Software Engineer', 'Product Manager', 'Data Scientist', 'Financial Analyst',
        'DevOps Architect', 'UX Designer', 'Systems Administrator', 'Marketing Director',
        'Database Administrator', 'Cybersecurity Specialist', 'Solutions Architect',
        'Full Stack Developer', 'Cloud Infrastructure Engineer', 'QA Lead', 'AI Research Scientist',
        'Network Engineer', 'Machine Learning Engineer', 'Risk Consultant', 'IT Director', 'CTO',
    ];

    protected const COMPANIES = [
        'Apex Innovations', 'Bright Horizons Ltd', 'Pacific Data Labs', 'Vanguard Equity',
        'CloudScale Solutions', 'Creativa Digital', 'Nexus Systems', 'Sunrise Media',
        'Nordic Shield AB', 'NeuralCore Labs', 'Iberia Tech Group', 'Delta Cloud Services',
        'Han River Ventures', 'Helvetia Advisory', 'Lusitania Networks', 'Emerald Digital Media',
        'Danube Cognitive', 'Fjord Quality Systems', 'Acme Corporation', 'Global Logic Inc',
    ];

    protected const COUNTRIES = [
        ['United States', 'New York', '100 Congress Ave'],
        ['United Kingdom', 'London', '25 Baker Street'],
        ['Australia', 'Sydney', '48 Martin Place'],
        ['Germany', 'Berlin', 'Friedrichstrasse 43'],
        ['France', 'Paris', '12 Rue de Rivoli'],
        ['Brazil', 'Sao Paulo', 'Av. Paulista 1000'],
        ['Italy', 'Rome', 'Via del Corso 18'],
        ['Japan', 'Tokyo', '6-10-1 Roppongi'],
        ['Canada', 'Toronto', '100 King St West'],
        ['Sweden', 'Stockholm', 'Kungsgatan 12'],
        ['Singapore', 'Singapore', '1 Fusionopolis Way'],
        ['Spain', 'Madrid', 'Paseo de la Castellana 80'],
        ['Netherlands', 'Amsterdam', 'Keizersgracht 421'],
        ['India', 'Mumbai', 'Bandra Kurla Complex'],
        ['Switzerland', 'Zurich', 'Bahnhofstrasse 45'],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);
        $startMem = memory_get_usage(true);

        $count = (int) $this->option('count');
        if ($count < 1) {
            $this->error('Record count must be greater than 0.');
            return self::FAILURE;
        }

        $prefix = (string) $this->option('prefix');
        $invalidCount = max(0, (int) $this->option('invalid'));
        $duplicateCount = max(0, (int) $this->option('duplicates'));

        // Determine destination output path
        $outputPath = $this->resolveOutputPath();

        $outputDir = dirname($outputPath);
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $this->info("=============================================================");
        $this->info(" Benchmark Excel Generator: {$count} Records");
        $this->info("=============================================================");
        $this->line(" Destination : {$outputPath}");
        $this->line(" Target Rows : " . number_format($count));
        $this->line(" ID Prefix   : {$prefix}");
        if ($invalidCount > 0) {
            $this->line(" Injected Invalid Rows   : " . number_format($invalidCount));
        }
        if ($duplicateCount > 0) {
            $this->line(" Injected Duplicate Rows : " . number_format($duplicateCount));
        }
        $this->line(" Memory at start : " . round($startMem / 1048576, 2) . " MB");
        $this->newLine();

        $writer = new Writer();
        $writer->openToFile($outputPath);

        // 1. Write Header Row
        $writer->addRow(Row::fromValues(ExcelParserService::TEMPLATE_HEADERS));

        $firstNameCount = count(self::FIRST_NAMES);
        $lastNameCount = count(self::LAST_NAMES);
        $professionCount = count(self::PROFESSIONS);
        $companyCount = count(self::COMPANIES);
        $countryCount = count(self::COUNTRIES);

        $progressStep = max(1, (int) ($count / 10)); // 10 progress intervals
        $lastProgressTime = $startTime;

        // 2. Stream Data Rows
        for ($i = 1; $i <= $count; $i++) {
            $customerId = sprintf('%s%06d', $prefix, $i);

            // Handle intentional duplicate injection if requested
            if ($duplicateCount > 0 && $i > ($count - $duplicateCount)) {
                // Point customer_id to an earlier row
                $targetDup = ($i % 100) + 1;
                $customerId = sprintf('%s%06d', $prefix, $targetDup);
            }

            // Select deterministic pool attributes
            $firstName = self::FIRST_NAMES[$i % $firstNameCount];
            $lastName = self::LAST_NAMES[($i * 3) % $lastNameCount];

            // Normalize names for RFC-compliant email and URL formats (strip spaces, apostrophes, accents)
            $slugFirst = preg_replace('/[^a-z0-9]/', '', strtolower($firstName));
            $slugLast = preg_replace('/[^a-z0-9]/', '', strtolower($lastName));
            $email = sprintf('user.%d.%s@benchmark.example.com', $i, $slugLast);

            // Phone: international format compliant with regex /^\+?[0-9\s().\-\/]{7,25}$/
            $phone = sprintf('+1-202-%03d-%04d', 500 + ($i % 400), 1000 + ($i % 9000));

            // DOB: between 1970 and 2002
            $dob = sprintf('%04d-%02d-%02d', 1970 + ($i % 33), 1 + ($i % 12), 1 + ($i % 28));

            $gender = ($i % 3 === 0) ? 'Female' : (($i % 3 === 1) ? 'Male' : null);
            $profession = self::PROFESSIONS[($i * 7) % $professionCount];
            $company = ($i % 7 === 0) ? null : self::COMPANIES[$i % $companyCount];

            $geo = self::COUNTRIES[$i % $countryCount];
            $country = $geo[0];
            $city = $geo[1];
            $address = ($i % 5 === 0) ? null : sprintf('%d %s, Ste %d', 100 + ($i % 900), $geo[2], 10 + ($i % 90));

            $socialUrl = ($i % 4 === 0) ? null : sprintf('https://linkedin.com/in/%s-%s-%d', $slugFirst, $slugLast, $i);
            $registrationDate = sprintf('202%d-%02d-%02d', 1 + ($i % 4), 1 + ($i % 12), 1 + ($i % 28));
            $status = ($i % 10 === 0) ? 'Inactive' : 'Active';

            // Handle intentional invalid row injection if requested
            if ($invalidCount > 0 && $i <= $invalidCount) {
                $email = "INVALID_EMAIL_FORMAT_{$i}"; // Will fail email validator
            }

            $rowValues = [
                $customerId,
                $firstName,
                $lastName,
                $email,
                $phone,
                $dob,
                $gender,
                $profession,
                $company,
                $country,
                $city,
                $address,
                $socialUrl,
                $registrationDate,
                $status,
            ];

            $writer->addRow(Row::fromValues($rowValues));

            if ($i % $progressStep === 0 || $i === $count) {
                $now = microtime(true);
                $pct = round(($i / $count) * 100);
                $stepSecs = round($now - $lastProgressTime, 2);
                $currentMem = round(memory_get_usage(true) / 1048576, 1);
                $this->line(sprintf(
                    " [%3d%%] %s / %s rows generated (+%s in %5.2fs) | Memory: %4.1f MB",
                    $pct,
                    number_format($i),
                    number_format($count),
                    number_format($progressStep),
                    $stepSecs,
                    $currentMem
                ));
                $lastProgressTime = $now;
            }
        }

        $writer->close();

        $endTime = microtime(true);
        $totalDuration = round($endTime - $startTime, 2);
        $peakMem = round(memory_get_peak_usage(true) / 1048576, 2);
        $fileBytes = file_exists($outputPath) ? filesize($outputPath) : 0;
        $fileMb = round($fileBytes / 1048576, 2);
        $rowsPerSec = ($totalDuration > 0) ? round($count / $totalDuration) : $count;

        $this->newLine();
        $this->info("=============================================================");
        $this->info(" Generator Summary");
        $this->info("=============================================================");
        $this->line(" File Path          : {$outputPath}");
        $this->line(" File Size          : {$fileMb} MB (" . number_format($fileBytes) . " bytes)");
        $this->line(" Total Rows         : " . number_format($count));
        $this->line(" Elapsed Time       : {$totalDuration} seconds");
        $this->line(" Generation Speed   : " . number_format($rowsPerSec) . " rows/second");
        $this->line(" Peak Memory Usage  : {$peakMem} MB");
        $this->info("=============================================================");

        return self::SUCCESS;
    }

    /**
     * Resolve the output file path based on arguments and configuration.
     */
    protected function resolveOutputPath(): string
    {
        $custom = $this->option('output');
        if ($custom) {
            return ($custom[0] === '/') ? $custom : base_path($custom);
        }

        // Default to storage/app/private/imports/benchmark_500k.xlsx
        $disk = config('excel_import.disk', 'local');
        $directory = config('excel_import.directory', 'imports');
        $root = config("filesystems.disks.{$disk}.root", storage_path('app/private'));

        return $root . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . 'benchmark_500k.xlsx';
    }
}
