<?php

namespace Tests\Feature\Api;

use App\Models\EmailVerificationCode;
use App\Models\Playlist;
use App\Models\Subscription;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_deletion_removes_owned_data_credentials_and_files_but_not_other_users(): void
    {
        Storage::fake('local'); Storage::fake('public');
        $user = User::factory()->artist()->create(['password' => 'Secret123!', 'avatar_path' => 'avatars/owned.png']);
        $other = User::factory()->artist()->create(['avatar_path' => 'avatars/other.png']);
        $track = Track::factory()->create(['user_id' => $user->id, 'audio_path' => 'tracks/owned.mp3', 'cover_path' => 'covers/owned.png']);
        $otherTrack = Track::factory()->create(['user_id' => $other->id]);
        $playlist = Playlist::factory()->create(['user_id' => $user->id]);
        $user->createToken('first'); $user->createToken('second');
        DB::table('sessions')->insert(['id' => 'owned-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'hash']);
        EmailVerificationCode::create(['email' => $user->email, 'purpose' => 'registration', 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10)]);
        Storage::disk('local')->put('tracks/owned.mp3', 'audio');
        foreach (['avatars/owned.png', 'avatars/other.png', 'covers/owned.png'] as $file) Storage::disk('public')->put($file, 'image');
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/me', ['password' => 'Secret123!', 'confirmation' => 'УДАЛИТЬ'])->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('tracks', ['id' => $track->id]);
        $this->assertDatabaseMissing('playlists', ['id' => $playlist->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('email_verification_codes', 0);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertDatabaseHas('tracks', ['id' => $otherTrack->id]);
        Storage::disk('local')->assertMissing('tracks/owned.mp3');
        Storage::disk('public')->assertMissing('avatars/owned.png');
        Storage::disk('public')->assertMissing('covers/owned.png');
        Storage::disk('public')->assertExists('avatars/other.png');
    }

    public function test_self_deletion_requires_password_and_explicit_confirmation(): void
    {
        $this->deleteJson('/api/v1/me')->assertUnauthorized();
        $user = User::factory()->create(['password' => 'Secret123!']); Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/me', ['password' => 'wrong', 'confirmation' => 'УДАЛИТЬ'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->deleteJson('/api/v1/me', ['password' => 'Secret123!', 'confirmation' => 'yes'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_only_admin_can_delete_another_account_and_id_must_match(): void
    {
        $user = User::factory()->create(); $other = User::factory()->create(); Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/admin/users/'.$other->id, ['confirmation_id' => $other->id])->assertForbidden();
        $admin = User::factory()->admin()->create(); Sanctum::actingAs($admin);
        $this->deleteJson('/api/v1/admin/users/'.$other->id, ['confirmation_id' => $user->id])->assertUnprocessable();
        $this->deleteJson('/api/v1/admin/users/'.$other->id, ['confirmation_id' => $other->id])->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->deleteJson('/api/v1/admin/users/'.$admin->id, ['confirmation_id' => $admin->id])->assertStatus(409);
    }

    public function test_last_administrator_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'Secret123!']); Sanctum::actingAs($admin);
        $this->deleteJson('/api/v1/me', ['password' => 'Secret123!', 'confirmation' => 'УДАЛИТЬ'])->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_stripe_is_cancelled_before_deletion_even_for_past_due_subscription(): void
    {
        config(['services.stripe.secret' => 'sk_test_fixture']);
        $user = User::factory()->artist()->create(['password' => 'Secret123!']);
        Subscription::create(['user_id' => $user->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_delete', 'plan' => 'artist', 'status' => 'past_due']);
        Http::fake(fn ($request) => Http::response(['id' => 'sub_delete', 'status' => $request->method() === 'DELETE' ? 'canceled' : 'past_due']));
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/me', ['password' => 'Secret123!', 'confirmation' => 'УДАЛИТЬ'])->assertNoContent();
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/subscriptions/sub_delete'));
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('subscriptions', ['user_id' => $user->id]);
    }

    public function test_failed_stripe_cancellation_keeps_account_tokens_and_files(): void
    {
        config(['services.stripe.secret' => 'sk_test_fixture']); Storage::fake('public');
        $user = User::factory()->artist()->create(['password' => 'Secret123!', 'avatar_path' => 'avatars/keep.png']);
        $user->createToken('keep'); Storage::disk('public')->put('avatars/keep.png', 'avatar');
        Subscription::create(['user_id' => $user->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_keep', 'plan' => 'artist', 'status' => 'active']);
        Http::fake(fn ($r) => $r->method() === 'GET' ? Http::response(['id' => 'sub_keep', 'status' => 'active']) : Http::response(['error' => 'unavailable'], 500));
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/me', ['password' => 'Secret123!', 'confirmation' => 'УДАЛИТЬ'])->assertServerError();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        Storage::disk('public')->assertExists('avatars/keep.png');
    }

    public function test_open_checkout_link_is_expired_before_account_is_deleted(): void
    {
        config(['services.stripe.secret' => 'sk_test_fixture']);
        $user = User::factory()->artist()->create(['password' => 'Secret123!']);
        DB::table('billing_checkout_sessions')->insert(['user_id' => $user->id, 'session_id' => 'cs_test_delete', 'created_at' => now()]);
        Http::fake(fn ($r) => Http::response(['id' => 'cs_test_delete', 'metadata' => ['user_id' => (string) $user->id], 'status' => $r->method() === 'POST' ? 'expired' : 'open']));
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/me', ['password' => 'Secret123!', 'confirmation' => 'УДАЛИТЬ'])->assertNoContent();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/cs_test_delete/expire'));
        $this->assertDatabaseCount('billing_checkout_sessions', 0);
    }
}
