<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\ImportRowError;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ImportAuthorizationTest extends TestCase
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

    public function test_user_can_only_view_their_own_imports_in_history_index(): void
    {
        // User A imports
        Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'user_a_import_1.xlsx',
            'file_path' => 'imports/test_a1.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);
        Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'user_a_import_2.xlsx',
            'file_path' => 'imports/test_a2.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);

        // User B imports
        Import::create([
            'user_id' => $this->userB->id,
            'filename' => 'user_b_import_1.xlsx',
            'file_path' => 'imports/test_b1.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);

        // Unowned legacy import (user_id = null)
        Import::create([
            'user_id' => null,
            'filename' => 'unowned_legacy.xlsx',
            'file_path' => 'imports/test_legacy.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);

        // User A's view
        $responseA = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/imports');

        $responseA->assertStatus(200);
        $filenamesA = collect($responseA->json('data'))->pluck('filename')->all();
        $this->assertContains('user_a_import_1.xlsx', $filenamesA);
        $this->assertContains('user_a_import_2.xlsx', $filenamesA);
        $this->assertNotContains('user_b_import_1.xlsx', $filenamesA);
        $this->assertNotContains('unowned_legacy.xlsx', $filenamesA);

        // User B's view
        $responseB = $this->actingAs($this->userB, 'sanctum')
            ->getJson('/api/imports');

        $responseB->assertStatus(200);
        $filenamesB = collect($responseB->json('data'))->pluck('filename')->all();
        $this->assertContains('user_b_import_1.xlsx', $filenamesB);
        $this->assertNotContains('user_a_import_1.xlsx', $filenamesB);
        $this->assertNotContains('user_a_import_2.xlsx', $filenamesB);
        $this->assertNotContains('unowned_legacy.xlsx', $filenamesB);
    }

    public function test_user_cannot_view_another_users_import_detail(): void
    {
        $importA = Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'user_a_private.xlsx',
            'file_path' => 'imports/test_a.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);

        // User B attempts to access User A's import detail
        $response = $this->actingAs($this->userB, 'sanctum')
            ->getJson("/api/imports/{$importA->id}");

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'This action is unauthorized.',
            ]);
    }

    public function test_user_cannot_view_unowned_legacy_import_detail(): void
    {
        $legacyImport = Import::create([
            'user_id' => null,
            'filename' => 'legacy_unowned.xlsx',
            'file_path' => 'imports/test_legacy.xlsx',
            'status' => Import::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/imports/{$legacyImport->id}");

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'This action is unauthorized.',
            ]);
    }

    public function test_user_cannot_view_another_users_import_errors(): void
    {
        $importA = Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'user_a_with_errors.xlsx',
            'file_path' => 'imports/test_a.xlsx',
            'status' => Import::STATUS_COMPLETED_WITH_ERRORS,
        ]);

        ImportRowError::create([
            'import_id' => $importA->id,
            'excel_row' => 2,
            'customer_id' => 'CUST_A_001',
            'rejection_reason' => 'Invalid email address',
        ]);

        // User B attempts to view User A's errors
        $response = $this->actingAs($this->userB, 'sanctum')
            ->getJson("/api/imports/{$importA->id}/errors");

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'This action is unauthorized.',
            ]);
    }

    public function test_user_cannot_download_another_users_errors_csv(): void
    {
        $importA = Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'user_a_csv.xlsx',
            'file_path' => 'imports/test_a.xlsx',
            'status' => Import::STATUS_COMPLETED_WITH_ERRORS,
        ]);

        ImportRowError::create([
            'import_id' => $importA->id,
            'excel_row' => 3,
            'customer_id' => 'CUST_A_SECRET',
            'rejection_reason' => 'Invalid date format',
        ]);

        // User B attempts to download User A's error CSV
        $response = $this->actingAs($this->userB, 'sanctum')
            ->getJson("/api/imports/{$importA->id}/errors.csv");

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'This action is unauthorized.',
            ]);
    }

    public function test_owner_can_view_their_own_import_details_and_errors_and_download_csv(): void
    {
        $importA = Import::create([
            'user_id' => $this->userA->id,
            'filename' => 'user_a_own.xlsx',
            'file_path' => 'imports/test_a_own.xlsx',
            'status' => Import::STATUS_COMPLETED_WITH_ERRORS,
            'total_rows' => 10,
            'processed_rows' => 10,
            'saved_rows' => 8,
            'rejected_rows' => 2,
        ]);

        ImportRowError::create([
            'import_id' => $importA->id,
            'excel_row' => 5,
            'customer_id' => 'CUST_005',
            'rejection_reason' => 'Duplicate ID',
        ]);

        // Detail
        $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/imports/{$importA->id}")
            ->assertStatus(200)
            ->assertJson([
                'id' => $importA->id,
                'filename' => 'user_a_own.xlsx',
                'status' => Import::STATUS_COMPLETED_WITH_ERRORS,
            ]);

        // Errors
        $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/imports/{$importA->id}/errors")
            ->assertStatus(200)
            ->assertJsonPath('data.0.customer_id', 'CUST_005');

        // CSV Download
        $responseCsv = $this->actingAs($this->userA, 'sanctum')
            ->get("/api/imports/{$importA->id}/errors.csv");

        $responseCsv->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $responseCsv->headers->get('Content-Type'));
    }
}
