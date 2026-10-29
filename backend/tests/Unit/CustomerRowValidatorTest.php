<?php

namespace Tests\Unit;

use App\Services\CustomerRowValidator;
use PHPUnit\Framework\TestCase;

class CustomerRowValidatorTest extends TestCase
{
    protected CustomerRowValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new CustomerRowValidator;
    }

    protected function validRow(): array
    {
        return [
            'customer_id' => 'CUST_100',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'email' => 'alice@example.com',
            'phone_number' => '+1-555-0199',
            'date_of_birth' => '1992-04-15',
            'gender' => 'Female',
            'profession' => 'Software Engineer',
            'company_name' => 'Acme Corp',
            'country' => 'United States',
            'city' => 'Seattle',
            'address' => '789 Pine St',
            'social_media_url' => 'https://linkedin.com/in/alicesmith',
            'registration_date' => '2024-01-10',
            'status' => 'Active',
        ];
    }

    public function test_valid_row_passes_validation(): void
    {
        $result = $this->validator->validate($this->validRow());

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
        $this->assertSame('CUST_100', $result['data']['customer_id']);
        $this->assertSame('alice@example.com', $result['data']['email']);
        $this->assertSame('Active', $result['data']['status']);
    }

    public function test_missing_required_fields_fails_validation(): void
    {
        $row = $this->validRow();
        unset($row['customer_id'], $row['first_name'], $row['email']);

        $result = $this->validator->validate($row);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Customer ID is required.', $result['error']);
        $this->assertStringContainsString('First Name is required.', $result['error']);
        $this->assertStringContainsString('Email is required.', $result['error']);
    }

    public function test_invalid_email_format_fails(): void
    {
        $row = $this->validRow();
        $row['email'] = 'not-a-valid-email';

        $result = $this->validator->validate($row);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Email must be a valid email address.', $result['error']);
    }

    public function test_invalid_phone_format_fails(): void
    {
        $row = $this->validRow();
        $row['phone_number'] = '123'; // Too short

        $result = $this->validator->validate($row);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Phone Number format is invalid.', $result['error']);
    }

    public function test_invalid_date_format_fails(): void
    {
        $row = $this->validRow();
        $row['date_of_birth'] = 'not-a-date';

        $result = $this->validator->validate($row);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Date of Birth (DOB) is required and must be a valid date.', $result['error']);
    }

    public function test_invalid_social_media_url_fails(): void
    {
        $row = $this->validRow();
        $row['social_media_url'] = 'invalid-url-without-protocol';

        $result = $this->validator->validate($row);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Social Media URL must be a valid URL.', $result['error']);
    }

    public function test_optional_fields_can_be_null(): void
    {
        $row = $this->validRow();
        $row['gender'] = null;
        $row['company_name'] = '';
        $row['address'] = null;
        $row['social_media_url'] = '';

        $result = $this->validator->validate($row);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['data']['gender']);
        $this->assertNull($result['data']['company_name']);
        $this->assertNull($result['data']['social_media_url']);
    }

    public function test_status_must_be_active_or_inactive(): void
    {
        $row = $this->validRow();
        $row['status'] = 'Pending';

        $result = $this->validator->validate($row);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Status must be either "Active" or "Inactive".', $result['error']);

        // Case-insensitivity check
        $row['status'] = 'inactive';
        $resultCase = $this->validator->validate($row);
        $this->assertTrue($resultCase['valid']);
        $this->assertSame('Inactive', $resultCase['data']['status']);
    }
}
