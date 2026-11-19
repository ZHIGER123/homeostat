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

    public function test_registration_sends_email_without_creating_an_account_or_token(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $challenge = $this->getJson('/api/v1/auth/captcha')->assertOk()->json();
        preg_match('/(\d+)\s*\+\s*(\d+)/u', $challenge['question'], $parts);
        $response = $this->postJson('/api/v1/register', ['name' => 'Новый', 'email' => 'new@example.com', 'password' => 'password1', 'password_confirmation' => 'password1', 'birth_date' => now()->subYears(20)->toDateString(), 'captcha_id' => $challenge['id'], 'captcha_answer' => (string) ((int) $parts[1] + (int) $parts[2])]);
        $response->assertStatus(202)->assertJsonPath('verification_required', true)->assertJsonMissingPath('token')->assertJsonMissingPath('user');
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\VerificationCodeMail::class, fn ($mail) => $mail->hasTo('new@example.com') && preg_match('/^\d{6}$/', $mail->code));
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $this->assertDatabaseHas('email_verification_codes', ['email' => 'new@example.com', 'purpose' => 'registration']);
        $record = \App\Models\EmailVerificationCode::where('email', 'new@example.com')->firstOrFail();
        $this->assertStringNotContainsString('password1', $record->payload);
        $record->update(['code_hash' => \Illuminate\Support\Facades\Hash::make('123456')]);
        $this->postJson('/api/v1/auth/email/verify-code', ['email' => 'new@example.com', 'code' => '123456'])->assertCreated()->assertJsonPath('user.role', 'listener')->assertJsonMissingPath('user.password');
        $this->assertNotNull(User::where('email', 'new@example.com')->firstOrFail()->email_verified_at);
    }

    public function test_registration_cannot_select_artist_role(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $challenge = $this->getJson('/api/v1/auth/captcha')->assertOk()->json();
        preg_match('/(\d+)\s*\+\s*(\d+)/u', $challenge['question'], $parts);
        $this->postJson('/api/v1/register', ['name' => 'Новый', 'email' => 'artist@example.com', 'password' => 'password1', 'password_confirmation' => 'password1', 'birth_date' => now()->subYears(20)->toDateString(), 'role' => 'artist', 'captcha_id' => $challenge['id'], 'captcha_answer' => (string) ((int) $parts[1] + (int) $parts[2])])->assertStatus(202);
        $record = \App\Models\EmailVerificationCode::where('email', 'artist@example.com')->firstOrFail();
        $this->assertSame('listener', json_decode($record->payload, true)['role']);
        $this->assertDatabaseMissing('users', ['email' => 'artist@example.com']);
    }

    public function test_email_code_distinguishes_new_and_existing_accounts(): void
    {
        foreach (['new-otp@example.com', 'existing-otp@example.com'] as $email) {
            \App\Models\EmailVerificationCode::create(['email' => $email, 'purpose' => 'registration', 'code_hash' => \Illuminate\Support\Facades\Hash::make('123456'), 'payload' => json_encode(['name' => 'OTP', 'role' => 'artist', 'birth_date' => '2000-01-01']), 'expires_at' => now()->addMinutes(10)]);
        }
        User::factory()->listener()->create(['email' => 'existing-otp@example.com']);
        $this->postJson('/api/v1/auth/email/verify-code', ['email' => 'new-otp@example.com', 'code' => '123456'])->assertCreated()->assertJsonPath('new_account', true)->assertJsonPath('user.role', 'listener');
        $this->postJson('/api/v1/auth/email/verify-code', ['email' => 'existing-otp@example.com', 'code' => '123456'])->assertCreated()->assertJsonPath('new_account', false);
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
        $this->postJson('/api/v1/tracks', [])->assertUnprocessable();
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

    public function test_registration_rejects_users_younger_than_sixteen(): void
    {
        $challenge = $this->getJson('/api/v1/auth/captcha')->assertOk()->json();
        preg_match('/(\d+)\s*\+\s*(\d+)/u', $challenge['question'], $parts);
        $this->postJson('/api/v1/register', ['name' => 'Юный', 'email' => 'young@example.com', 'password' => 'password1', 'password_confirmation' => 'password1', 'birth_date' => now()->subYears(15)->toDateString(), 'captcha_id' => $challenge['id'], 'captcha_answer' => (string) ((int) $parts[1] + (int) $parts[2])])
            ->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
        $this->postJson('/api/v1/auth/email/request-code', ['name' => 'Юный', 'email' => 'young@example.com', 'birth_date' => now()->subYears(15)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
        $this->assertDatabaseMissing('users', ['email' => 'young@example.com']);
    }

    public function test_google_oauth_and_exchange_are_disabled(): void
    {
        $this->getJson('/api/v1/auth/google/redirect?age_confirmed=1')->assertNotFound();
        $this->getJson('/api/v1/auth/google/callback?code=obsolete')->assertNotFound();
        $this->postJson('/api/v1/auth/oauth/exchange', ['handle' => str_repeat('a', 64)])->assertNotFound();
    }

    public function test_admin_can_stream_pending_track_for_moderation_but_guest_cannot(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $track = Track::factory()->pending()->create(['audio_path' => 'tracks/pending.mp3']);
        \Illuminate\Support\Facades\Storage::disk('local')->put('tracks/pending.mp3', 'fake-audio');
        $this->get('/api/v1/tracks/'.$track->id.'/stream')->assertNotFound();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->get('/api/v1/tracks/'.$track->id.'/stream')->assertOk();
    }
}
