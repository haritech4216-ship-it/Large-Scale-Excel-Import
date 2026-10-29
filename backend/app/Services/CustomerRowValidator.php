<?php

namespace App\Services;

use DateTimeInterface;
use Exception;
use Illuminate\Support\Carbon;

class CustomerRowValidator
{
    /**
     * Validate an associative row of customer data.
     *
     * @param  array<string, mixed>  $rawRow
     * @return array{valid: bool, data: ?array, error: ?string}
     */
    public function validate(array $rawRow): array
    {
        $errors = [];

        // 1. Customer ID (Required)
        $customerId = isset($rawRow['customer_id']) ? trim((string) $rawRow['customer_id']) : '';
        if ($customerId === '') {
            $errors[] = 'Customer ID is required.';
        } elseif (strlen($customerId) > 100) {
            $errors[] = 'Customer ID must not exceed 100 characters.';
        }

        // 2. First Name (Required)
        $firstName = isset($rawRow['first_name']) ? trim((string) $rawRow['first_name']) : '';
        if ($firstName === '') {
            $errors[] = 'First Name is required.';
        } elseif (strlen($firstName) > 150) {
            $errors[] = 'First Name must not exceed 150 characters.';
        }

        // 3. Last Name (Required)
        $lastName = isset($rawRow['last_name']) ? trim((string) $rawRow['last_name']) : '';
        if ($lastName === '') {
            $errors[] = 'Last Name is required.';
        } elseif (strlen($lastName) > 150) {
            $errors[] = 'Last Name must not exceed 150 characters.';
        }

        // 4. Email (Required, valid email)
        $email = isset($rawRow['email']) ? trim((string) $rawRow['email']) : '';
        if ($email === '') {
            $errors[] = 'Email is required.';
        } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email must be a valid email address.';
        } elseif (strlen($email) > 255) {
            $errors[] = 'Email must not exceed 255 characters.';
        }

        // 5. Phone Number (Required, valid format)
        $phone = isset($rawRow['phone_number']) ? trim((string) $rawRow['phone_number']) : '';
        if ($phone === '') {
            $errors[] = 'Phone Number is required.';
        } elseif (! $this->isValidPhone($phone)) {
            $errors[] = 'Phone Number format is invalid.';
        }

        // 6. Date of Birth (Required, valid date)
        $dobRaw = $rawRow['date_of_birth'] ?? null;
        $dob = $this->parseDate($dobRaw);
        if ($dob === null) {
            $errors[] = 'Date of Birth (DOB) is required and must be a valid date.';
        }

        // 7. Gender (Optional)
        $gender = isset($rawRow['gender']) && trim((string) $rawRow['gender']) !== ''
            ? trim((string) $rawRow['gender'])
            : null;
        if ($gender !== null && strlen($gender) > 50) {
            $errors[] = 'Gender must not exceed 50 characters.';
        }

        // 8. Profession (Required)
        $profession = isset($rawRow['profession']) ? trim((string) $rawRow['profession']) : '';
        if ($profession === '') {
            $errors[] = 'Profession is required.';
        } elseif (strlen($profession) > 150) {
            $errors[] = 'Profession must not exceed 150 characters.';
        }

        // 9. Company Name (Optional)
        $companyName = isset($rawRow['company_name']) && trim((string) $rawRow['company_name']) !== ''
            ? trim((string) $rawRow['company_name'])
            : null;
        if ($companyName !== null && strlen($companyName) > 150) {
            $errors[] = 'Company Name must not exceed 150 characters.';
        }

        // 10. Country (Required)
        $country = isset($rawRow['country']) ? trim((string) $rawRow['country']) : '';
        if ($country === '') {
            $errors[] = 'Country is required.';
        } elseif (strlen($country) > 100) {
            $errors[] = 'Country must not exceed 100 characters.';
        }

        // 11. City (Required)
        $city = isset($rawRow['city']) ? trim((string) $rawRow['city']) : '';
        if ($city === '') {
            $errors[] = 'City is required.';
        } elseif (strlen($city) > 100) {
            $errors[] = 'City must not exceed 100 characters.';
        }

        // 12. Address (Optional)
        $address = isset($rawRow['address']) && trim((string) $rawRow['address']) !== ''
            ? trim((string) $rawRow['address'])
            : null;

        // 13. Social Media URL (Optional, valid URL)
        $socialUrl = isset($rawRow['social_media_url']) && trim((string) $rawRow['social_media_url']) !== ''
            ? trim((string) $rawRow['social_media_url'])
            : null;
        if ($socialUrl !== null) {
            if (! filter_var($socialUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Social Media URL must be a valid URL.';
            } elseif (strlen($socialUrl) > 500) {
                $errors[] = 'Social Media URL must not exceed 500 characters.';
            }
        }

        // 14. Registration Date (Required, valid date)
        $regDateRaw = $rawRow['registration_date'] ?? null;
        $registrationDate = $this->parseDate($regDateRaw);
        if ($registrationDate === null) {
            $errors[] = 'Registration Date is required and must be a valid date.';
        }

        // 15. Status (Required, Active / Inactive)
        $statusRaw = isset($rawRow['status']) ? trim((string) $rawRow['status']) : '';
        $normalizedStatus = null;
        if (strcasecmp($statusRaw, 'Active') === 0) {
            $normalizedStatus = 'Active';
        } elseif (strcasecmp($statusRaw, 'Inactive') === 0) {
            $normalizedStatus = 'Inactive';
        } else {
            $errors[] = 'Status must be either "Active" or "Inactive".';
        }

        if (! empty($errors)) {
            return [
                'valid' => false,
                'data' => null,
                'customer_id' => $customerId !== '' ? $customerId : null,
                'error' => implode(' ', $errors),
            ];
        }

        return [
            'valid' => true,
            'customer_id' => $customerId,
            'data' => [
                'customer_id' => $customerId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => strtolower($email),
                'phone_number' => $phone,
                'date_of_birth' => $dob,
                'gender' => $gender,
                'profession' => $profession,
                'company_name' => $companyName,
                'country' => $country,
                'city' => $city,
                'address' => $address,
                'social_media_url' => $socialUrl,
                'registration_date' => $registrationDate,
                'status' => $normalizedStatus,
            ],
            'error' => null,
        ];
    }

    /**
     * Validate phone number format.
     */
    private function isValidPhone(string $phone): bool
    {
        // Standard international or domestic phone format:
        // Allows optional leading '+', digits, spaces, parentheses, hyphens, and dots.
        // Requires between 7 and 25 valid digits/characters.
        $digitsOnly = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($digitsOnly) < 7 || strlen($digitsOnly) > 18) {
            return false;
        }

        return (bool) preg_match('/^\+?[0-9\s().\-\/]{7,25}$/', $phone);
    }

    /**
     * Parse date value to Y-m-d string.
     */
    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        // Handle numeric Excel date serial number (days since 1899-12-30)
        if (is_numeric($str) && (float) $str > 1000 && (float) $str < 100000) {
            $days = (int) $str;
            $unixTime = ($days - 25569) * 86400;
            if ($unixTime > 0) {
                return gmdate('Y-m-d', $unixTime);
            }
        }

        try {
            $parsed = Carbon::parse($str);
            // Verify reasonable date range
            if ($parsed->year < 1900 || $parsed->year > 2100) {
                return null;
            }

            return $parsed->format('Y-m-d');
        } catch (Exception) {
            return null;
        }
    }
}
