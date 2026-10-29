<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'auth_test_user@example.com',
            'password' => Hash::make('SecretPass123!'),
        ]);
    }

    public function test_user_can_login_with_valid_credentials_and_receive_token(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'auth_test_user@example.com',
            'password' => 'SecretPass123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
                'message',
            ])
            ->assertJson([
                'user' => [
                    'id' => $this->user->id,
                    'email' => 'auth_test_user@example.com',
                ],
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'auth_test_user@example.com',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid email or password.',
            ]);
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nonexistent_user@example.com',
            'password' => 'AnyPassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid email or password.',
            ]);
    }

    public function test_authenticated_user_can_access_me_endpoint(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'user' => [
                    'id' => $this->user->id,
                    'email' => $this->user->email,
                ],
            ]);
    }

    public function test_user_can_logout_and_revoke_token(): void
    {
        $token = $this->user->createToken('test-logout-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Successfully logged out.',
            ]);

        // Reset cached authentication state in test container
        $this->app['auth']->forgetGuards();

        // Next request with same revoked token must be rejected with 401
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_unauthenticated_request_to_store_import_is_rejected_with_401(): void
    {
        $this->postJson('/api/imports', [])
            ->assertStatus(401);
    }

    public function test_unauthenticated_request_to_index_imports_is_rejected_with_401(): void
    {
        $this->getJson('/api/imports')
            ->assertStatus(401);
    }

    public function test_unauthenticated_request_to_show_import_is_rejected_with_401(): void
    {
        $this->getJson('/api/imports/1')
            ->assertStatus(401);
    }

    public function test_unauthenticated_request_to_import_errors_is_rejected_with_401(): void
    {
        $this->getJson('/api/imports/1/errors')
            ->assertStatus(401);
    }

    public function test_unauthenticated_request_to_download_errors_csv_is_rejected_with_401(): void
    {
        $this->getJson('/api/imports/1/errors.csv')
            ->assertStatus(401);
    }
}
