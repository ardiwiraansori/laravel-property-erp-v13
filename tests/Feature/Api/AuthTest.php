<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

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

        $permission = Permission::findOrCreate(
            PermissionName::DashboardView->value,
            'web',
        );

        $role = Role::findOrCreate(
            RoleName::Owner->value,
            'web',
        );

        $role->givePermissionTo($permission);

        $user->assignRole($role);

        $loginResponse = $this->fromSpa()->postJson('/api/login', [
            'email' => 'ardi@example.com',
            'password' => 'password',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.roles.0', RoleName::Owner->value)
            ->assertJsonPath(
                'user.permissions.0',
                PermissionName::DashboardView->value,
            );

        $this->assertAuthenticatedAs($user);

        $userResponse = $this->fromSpa()->getJson('/api/user');

        $userResponse
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.roles.0', RoleName::Owner->value)
            ->assertJsonPath(
                'data.permissions.0',
                PermissionName::DashboardView->value,
            );
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
