<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->agent()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.user.role', 'agent')
            ->assertJsonStructure(['data' => ['access_token', 'expires_in', 'user' => ['id', 'email', 'role']]]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid email or password.');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_login_validates_input(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertTooManyRequests();
    }

    public function test_registration_always_creates_a_customer(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => 'admin', // must be ignored
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'customer');

        $this->assertSame(UserRole::Customer, User::where('email', 'jane@example.com')->first()->role);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/register', [
            'name' => 'Dup',
            'email' => $user->email,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_deactivated_user_cannot_re_register_with_the_same_email(): void
    {
        $user = User::factory()->inactive()->create();

        $this->postJson('/api/auth/register', [
            'name' => 'Comeback',
            'email' => $user->email,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonMissingPath('data.access_token');

        // No second account was created and the original stays deactivated.
        $this->assertSame(1, User::where('email', $user->email)->count());
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->customer()->create();

        $this->withToken(auth('api')->login($user))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'customer');
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_unauthenticated_request_without_json_accept_header_gets_401_not_a_redirect(): void
    {
        // e.g. opening the URL in a browser address bar: no "Accept: application/json".
        $this->get('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_token_of_deactivated_user_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);
        $user->update(['is_active' => false]);
        auth('api')->forgetUser();

        $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();
    }

    public function test_logout_invalidates_the_token(): void
    {
        $token = auth('api')->login(User::factory()->create());

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        auth('api')->forgetUser();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_refresh_issues_a_new_token(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $newToken = $this->withToken($token)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->json('data.access_token');

        $this->assertNotSame($token, $newToken);
    }

    public function test_role_middleware_blocks_other_roles(): void
    {
        Route::middleware(['api', 'auth:api', 'role:admin'])
            ->get('/api/_test/admin-only', fn () => 'ok');

        $this->withToken(auth('api')->login(User::factory()->customer()->create()))
            ->getJson('/api/_test/admin-only')
            ->assertForbidden();

        auth('api')->forgetUser();

        $this->withToken(auth('api')->login(User::factory()->admin()->create()))
            ->getJson('/api/_test/admin-only')
            ->assertOk();
    }
}
