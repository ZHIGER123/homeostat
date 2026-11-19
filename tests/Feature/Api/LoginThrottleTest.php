<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_aliases_share_a_limit_but_different_accounts_and_roles_are_isolated(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.51']);
        User::factory()->create(['email' => 'limited@example.com', 'password' => 'password']);
        foreach (range(1, 5) as $attempt) {
            $route = $attempt % 2 ? '/api/v1/login' : '/api/v1/auth/login';
            $this->postJson($route, ['email' => ' LIMITED@EXAMPLE.COM ', 'password' => 'wrong'])
                ->assertUnauthorized();
        }
        $this->postJson('/api/v1/login', ['email' => 'limited@example.com', 'password' => 'password'])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonStructure(['message', 'retry_after']);
        foreach (['listener', 'artist', 'admin'] as $role) {
            User::factory()->create(['email' => $role.'@example.com', 'password' => 'password', 'role' => $role]);
            $this->postJson('/api/v1/login', ['email' => $role.'@example.com', 'password' => 'password'])
                ->assertOk()->assertJsonStructure(['token'])->assertJsonPath('user.role', $role);
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/login', ['email' => 'limited@example.com', 'password' => 'password'])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_ip_limit_still_limits_attempts_across_different_emails(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.52']);
        foreach (range(1, 60) as $attempt) {
            $this->postJson('/api/v1/login', ['email' => 'missing'.$attempt.'@example.com', 'password' => 'wrong'])
                ->assertUnauthorized();
        }
        $this->postJson('/api/v1/login', ['email' => 'another@example.com', 'password' => 'wrong'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }
}
