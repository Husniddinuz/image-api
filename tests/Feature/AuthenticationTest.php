<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_register_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
        $this->assertNotSame('correct-horse-battery-staple', User::first()->password);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Impostor',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_user_can_log_in(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_login_fails_with_the_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'not-the-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_does_not_reveal_whether_an_account_exists(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $known = $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong']);
        $unknown = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('errors.email'), $unknown->json('errors.email'));
    }

    public function test_private_routes_reject_anonymous_callers(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/images')->assertUnauthorized();
        $this->postJson('/api/images')->assertUnauthorized();
        $this->getJson('/api/images/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertUnauthorized();
        $this->deleteJson('/api/images/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertUnauthorized();
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $this->withToken('definitely-not-a-real-token')
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_logging_out_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('phone')->plainTextToken;
        $laptop = $user->createToken('laptop')->plainTextToken;

        $this->withToken($phone)->postJson('/api/auth/logout')->assertOk();

        $this->forgetResolvedUser()->withToken($phone)->getJson('/api/auth/me')->assertUnauthorized();
        $this->forgetResolvedUser()->withToken($laptop)->getJson('/api/auth/me')->assertOk();
    }
}
