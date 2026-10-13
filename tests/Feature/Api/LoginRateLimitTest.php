<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_failed_logins_are_rate_limited(): void
    {
        User::factory()->create(['email' => 'limited@example.com', 'password' => 'secret']);
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ]);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'limited@example.com', 'password' => 'incorrect',
            ])->assertStatus(422);
        }
        $this->postJson('/api/login', [
            'email' => 'limited@example.com', 'password' => 'incorrect',
        ])->assertStatus(429);
    }
}
