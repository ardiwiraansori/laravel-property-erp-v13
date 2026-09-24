<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Auth;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function fromSpa(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
            'Accept' => 'application/json',
        ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'ardi@example.com',
            'password' => 'password',
        ]);

        $response = $this->fromSpa()->postJson('/api/login', [
            'email' => 'ardi@example.com',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'The provided credentials are incorrect.',
            ]);

        $this->assertGuest();
    }

    public function test_user_can_login_and_access_authenticated_user_endpoint(): void
    {
        $user = User::factory()->create([
            'email' => 'ardi@example.com',
            'password' => 'password',
        ]);

        $loginResponse = $this->fromSpa()->postJson('/api/login', [
            'email' => 'ardi@example.com',
            'password' => 'password',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('user.email', $user->email);

        $this->assertAuthenticatedAs($user);

        $userResponse = $this->fromSpa()->getJson('/api/user');

        $userResponse
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'ardi@example.com',
            'password' => 'password',
        ]);

        $this->fromSpa()->postJson('/api/login', [
            'email' => 'ardi@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->assertAuthenticatedAs($user);

        $logoutResponse = $this->fromSpa()->postJson('/api/logout');

        $logoutResponse
            ->assertOk()
            ->assertJson([
                'message' => 'Logout successful.',
            ]);

        $this->assertGuest('web');
        
        Auth::forgetGuards();

        $this->fromSpa()
            ->getJson('/api/user')
            ->assertUnauthorized();
    }
}
