<?php

namespace Tests\Feature\Api;

use App\Models\Comment;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_listener_and_returns_token_without_password(): void
    {
        $response = $this->postJson('/api/v1/register', ['name' => 'Новый', 'email' => 'new@example.com', 'password' => 'password1', 'password_confirmation' => 'password1']);
        $response->assertCreated()->assertJsonPath('user.role', 'listener')->assertJsonStructure(['user', 'token'])->assertJsonMissingPath('user.password');
        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'role' => 'listener']);
        $this->assertNotSame('password1', User::where('email', 'new@example.com')->firstOrFail()->password);
    }

    public function test_registration_rejects_admin_role_and_duplicate_email(): void
    {
        User::factory()->create(['email' => 'used@example.com']);
        $this->postJson('/api/v1/register', ['name' => 'Имя', 'email' => 'used@example.com', 'password' => 'password1', 'password_confirmation' => 'password1', 'role' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'role']);
    }

    public function test_login_current_user_logout_and_revoked_token(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com', 'password' => 'password']);
        $this->postJson('/api/v1/login', ['email' => 'test@example.com', 'password' => 'wrong'])->assertStatus(401)->assertJsonPath('message', 'Неверный email или пароль.');
        $login = $this->postJson('/api/v1/login', ['email' => 'test@example.com', 'password' => 'password'])->assertOk();
        $token = $login->json('token');
        $this->assertNotEmpty($token);
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_protected_routes_require_auth_and_role(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonStructure(['message']);
        $this->withHeader('Authorization', 'Bearer invalid')->getJson('/api/v1/me')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->listener()->create());
        $this->postJson('/api/v1/genres', ['name' => 'Rock'])->assertForbidden();
        $this->getJson('/api/v1/admin/tracks')->assertForbidden();
        $this->postJson('/api/v1/tracks', [])->assertForbidden();
    }

    public function test_owners_and_admins_are_enforced_and_spoofed_user_id_is_ignored(): void
    {
        $owner = User::factory()->artist()->create();
        $other = User::factory()->artist()->create();
        $track = Track::factory()->published()->create(['user_id' => $owner->id]);
        $playlist = Playlist::factory()->create(['user_id' => $owner->id]);
        $comment = Comment::factory()->create(['user_id' => $owner->id]);
        Sanctum::actingAs($other);
        $this->patchJson('/api/v1/tracks/'.$track->id, ['title' => 'Чужой'])->assertForbidden();
        $this->getJson('/api/v1/playlists/'.$playlist->id)->assertForbidden();
        $this->deleteJson('/api/v1/playlists/'.$playlist->id)->assertForbidden();
        $this->deleteJson('/api/v1/comments/'.$comment->id)->assertForbidden();
        $this->putJson('/api/v1/users/'.$owner->id.'/likes/'.$track->id)->assertForbidden();
        $this->postJson('/api/v1/playlists', ['user_id' => $owner->id, 'title' => 'Мой'])->assertCreated()->assertJsonPath('data.owner.id', $other->id);
        $this->getJson('/api/v1/playlists')->assertOk()->assertJsonCount(1, 'data');
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->deleteJson('/api/v1/comments/'.$comment->id)->assertNoContent();
        $this->deleteJson('/api/v1/playlists/'.$playlist->id)->assertNoContent();
    }

    public function test_profile_update_never_changes_role_or_block_state(): void
    {
        $user = User::factory()->listener()->create();
        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/me', ['name' => 'Новое имя', 'role' => 'admin', 'is_blocked' => true])
            ->assertOk()->assertJsonPath('data.name', 'Новое имя')->assertJsonPath('data.role', 'listener');
        $this->assertFalse($user->fresh()->is_blocked);
    }

    public function test_unpublished_track_and_comments_are_hidden_from_guest(): void
    {
        $track = Track::factory()->pending()->create();
        $this->getJson('/api/v1/tracks/'.$track->id)->assertNotFound();
        $this->getJson('/api/v1/tracks/'.$track->id.'/comments')->assertNotFound();
    }

    public function test_blocked_user_cannot_use_a_valid_token(): void
    {
        $blocked = User::factory()->blocked()->create();
        Sanctum::actingAs($blocked);
        $this->getJson('/api/v1/me')->assertForbidden();
    }
}
