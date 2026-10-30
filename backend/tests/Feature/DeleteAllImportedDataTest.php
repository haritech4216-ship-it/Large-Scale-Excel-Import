<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Import;
use App\Models\ImportChunk;
use App\Models\ImportRowError;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DeleteAllImportedDataTest extends TestCase
{
    use DatabaseTransactions;

    protected User $userA;

    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create([
            'email' => 'user_a_'.uniqid().'@example.com',
        ]);

        $this->userB = User::factory()->create([
            'email' => 'user_b_'.uniqid().'@example.com',
        ]);
    }

    protected function createCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'customer_id' => 'CUST-'.uniqid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.'.uniqid().'@example.com',
            'phone_number' => '+1-555-0100',
            'date_of_birth' => '1990-01-01',
            'gender' => 'Male',
            'profession' => 'Engineer',
            'company_name' => 'Acme Corp',
            'country' => 'United States',
            'city' => 'Chicago',
            'address' => '123 Main St',
            'social_media_url' => null,
            'registration_date' => '2023-01-01',
            'status' => 'Active',
            'import_id' => null,
        ], $attributes));
    }

    /**
     * 1. Unauthorized requests are rejected.
     */
    public function test_unauthorized_requests_are_rejected(): void
    {
        $this->getJson('/api/imported-data')->assertStatus(401);
        $this->deleteJson('/api/imported-data', ['confirmation' => 'DELETE ALL'])->assertStatus(401);
    }

    /**
     * 2. Deletion requires exact confirmation phrase 'DELETE ALL'.
     * Missing or non-matching confirmation leaves records untouched.
     */
    public function test_deletion_requires_explicit_confirmation_phrase(): void
    {
        $import = Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'user_a.xlsx',
            'file_path' => 'imports/test.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);

        $cust = $this->createCustomer(['import_id' => $import->id]);

        // Missing confirmation
        $res1 = $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/imported-data', []);
        $res1->assertStatus(422)
            ->assertJsonValidationErrors(['confirmation']);

        // Case-sensitive mismatch
        $res2 = $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/imported-data', ['confirmation' => 'delete all']);
        $res2->assertStatus(422);

        // Random text
        $res3 = $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/imported-data', ['confirmation' => 'CONFIRM']);
        $res3->assertStatus(422);

        // Record must remain untouched
        $this->assertDatabaseHas('customers', ['id' => $cust->id]);
    }

    /**
     * 3. Successful confirmation deletes only eligible imported records for the authenticated user.
     */
    public function test_successful_confirmation_deletes_only_eligible_imported_records(): void
    {
        $import = Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'user_a_batch.xlsx',
            'file_path' => 'imports/test.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);

        $cust1 = $this->createCustomer(['import_id' => $import->id]);
        $cust2 = $this->createCustomer(['import_id' => $import->id]);
        $cust3 = $this->createCustomer(['import_id' => $import->id]);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/imported-data', ['confirmation' => 'DELETE ALL']);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'deleted_count' => 3,
            ]);

        $this->assertDatabaseMissing('customers', ['id' => $cust1->id]);
        $this->assertDatabaseMissing('customers', ['id' => $cust2->id]);
        $this->assertDatabaseMissing('customers', ['id' => $cust3->id]);
    }

    /**
     * 4. Unrelated and pre-existing records remain untouched.
     * Records with import_id IS NULL or belonging to another user are never deleted.
     */
    public function test_unrelated_and_preexisting_records_remain_untouched(): void
    {
        // User A import
        $importA = Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'user_a.xlsx',
            'file_path' => 'imports/test_a.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);
        $custA = $this->createCustomer(['import_id' => $importA->id]);

        // User B import
        $importB = Import::create([
            'user_id' => $this->userB->id,
            'filename' => 'user_b.xlsx',
            'file_path' => 'imports/test_b.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);
        $custB = $this->createCustomer(['import_id' => $importB->id]);

        // Pre-existing customer (unrelated to any import)
        $custPreExisting = $this->createCustomer(['import_id' => null]);

        // User A triggers Delete All
        $response = $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/imported-data', ['confirmation' => 'DELETE ALL']);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'deleted_count' => 1,
            ]);

        // Only User A's imported customer was deleted
        $this->assertDatabaseMissing('customers', ['id' => $custA->id]);

        // Pre-existing and User B's records MUST remain in database
        $this->assertDatabaseHas('customers', ['id' => $custPreExisting->id]);
        $this->assertDatabaseHas('customers', ['id' => $custB->id]);
    }

    /**
     * 5. Import history, chunks, row errors, and files are never deleted.
     */
    public function test_import_history_chunks_and_errors_are_never_deleted(): void
    {
        $import = Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'preserved_import.xlsx',
            'file_path' => 'imports/preserved.xlsx',
            'status' => Import::STATUS_COMPLETED,
            'saved_rows' => 1,
            'rejected_rows' => 1,
        ]);

        $chunk = ImportChunk::create([
            'import_id' => $import->id,
            'chunk_number' => 1,
            'start_row' => 2,
            'end_row' => 3,
            'status' => ImportChunk::STATUS_COMPLETED,
        ]);

        $error = ImportRowError::create([
            'import_id' => $import->id,
            'excel_row' => 3,
            'customer_id' => 'INVALID_01',
            'rejection_reason' => 'Invalid email address.',
        ]);

        $cust = $this->createCustomer(['import_id' => $import->id]);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/imported-data', ['confirmation' => 'DELETE ALL']);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'deleted_count' => 1]);

        // Customer was deleted
        $this->assertDatabaseMissing('customers', ['id' => $cust->id]);

        // Import, chunk, and error records are completely preserved
        $this->assertDatabaseHas('imports', ['id' => $import->id]);
        $this->assertDatabaseHas('import_chunks', ['id' => $chunk->id]);
        $this->assertDatabaseHas('import_row_errors', ['id' => $error->id]);
    }

    /**
     * 6. Empty datasets are handled gracefully.
     */
    public function test_empty_datasets_handled_correctly(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/imported-data', ['confirmation' => 'DELETE ALL']);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'deleted_count' => 0,
            ]);
    }

    /**
     * 7. Repeated requests do not cause unintended deletion.
     */
    public function test_repeated_requests_do_not_cause_unintended_deletion(): void
    {
        $import = Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'idempotent_test.xlsx',
            'file_path' => 'imports/idempotent.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);
        $cust = $this->createCustomer(['import_id' => $import->id]);

        // First call deletes 1 record
        $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/imported-data', ['confirmation' => 'DELETE ALL'])
            ->assertStatus(200)
            ->assertJson(['deleted_count' => 1]);

        // Second call cleanly deletes 0 records
        $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/imported-data', ['confirmation' => 'DELETE ALL'])
            ->assertStatus(200)
            ->assertJson(['deleted_count' => 0]);
    }

    /**
     * 8. Index endpoint lists imported data with search, pagination, and eligible deletion count.
     */
    public function test_index_listing_and_search_scoped_to_user(): void
    {
        $importA = Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'search_a.xlsx',
            'file_path' => 'imports/search_a.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);

        $this->createCustomer([
            'import_id' => $importA->id,
            'customer_id' => 'SEARCH-ALICE',
            'first_name' => 'Alice',
            'email' => 'alice@example.com',
        ]);
        $this->createCustomer([
            'import_id' => $importA->id,
            'customer_id' => 'SEARCH-BOB',
            'first_name' => 'Bob',
            'email' => 'bob@example.com',
        ]);

        // Pre-existing customer without import_id must not appear
        $this->createCustomer([
            'import_id' => null,
            'customer_id' => 'SEARCH-GHOST',
            'first_name' => 'Ghost',
        ]);

        $res = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/imported-data');

        $res->assertStatus(200)
            ->assertJson([
                'total' => 2,
                'total_eligible_count' => 2,
            ])
            ->assertJsonFragment(['customer_id' => 'SEARCH-ALICE'])
            ->assertJsonFragment(['customer_id' => 'SEARCH-BOB'])
            ->assertJsonMissing(['customer_id' => 'SEARCH-GHOST']);

        // Search filtering
        $searchRes = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/imported-data?search=Alice');

        $searchRes->assertStatus(200)
            ->assertJson(['total' => 1, 'total_eligible_count' => 2])
            ->assertJsonFragment(['customer_id' => 'SEARCH-ALICE'])
            ->assertJsonMissing(['customer_id' => 'SEARCH-BOB']);
    }
}
