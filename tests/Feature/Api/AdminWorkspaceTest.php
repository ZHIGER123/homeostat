<?php

namespace Tests\Feature\Api;

use App\Models\EmailVerificationCode;
use App\Models\Track;
use App\Models\User;
use App\Rules\MinimumAge;
use App\Services\EmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_age_validation_including_exact_birthday_and_invalid_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00'));
        try {
            foreach (['2010-11-19' => true, '2010-11-20' => false, '2012-02-29' => false, '2008-02-29' => true, '2000-02-30' => false, '2027-01-01' => false, '1899-12-31' => false, '' => false, '2000-1-1' => false] as $date => $valid) {
                $validator = Validator::make(['birth_date' => $date], ['birth_date' => ['required', 'date_format:Y-m-d', new MinimumAge]]);
                $this->assertSame($valid, $validator->passes(), $date);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_directory_requires_admin_and_never_leaks_credentials(): void
    {
        $user = User::factory()->create(['birth_date' => '2000-01-01']);
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/users/'.$user->id)->assertForbidden();
        $this->patchJson('/api/v1/admin/users/'.$user->id, ['name' => 'No'])->assertForbidden();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/users/'.$user->id)->assertOk()
            ->assertJsonPath('data.id', $user->id)->assertJsonPath('data.birth_date', '2000-01-01')
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.oauth_id')->assertJsonMissingPath('data.tokens');
    }

    public function test_directory_search_filters_and_pagination(): void
    {
        $user = User::factory()->artist()->blocked()->create(['email' => 'needle@example.com']);
        User::factory()->count(3)->listener()->create();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/users?q=needle&role=artist&blocked=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $user->id);
        $this->getJson('/api/v1/admin/users?q='.$user->id)->assertOk()->assertJsonPath('data.0.id', $user->id);
        $this->getJson('/api/v1/admin/users?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
        $this->getJson('/api/v1/admin/users?per_page=1000')->assertUnprocessable();
        $this->getJson('/api/v1/admin/users?role=unknown')->assertUnprocessable();
    }

    public function test_profile_editing_validates_unique_email_and_prevents_admin_escalation(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->listener()->create();
        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/admin/users/'.$user->id, ['name' => 'Updated', 'role' => 'artist', 'password' => 'forged'])
            ->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonPath('data.role', 'artist');
        $this->assertNotSame('forged', $user->fresh()->password);
        $this->patchJson('/api/v1/admin/users/'.$user->id, ['role' => 'admin'])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/users/'.$admin->id, ['role' => 'listener'])->assertStatus(409);
        $this->patchJson('/api/v1/admin/users/'.$user->id, ['email' => $admin->email])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/users/'.$user->id, ['email' => 'changed@example.com'])->assertOk()->assertJsonPath('data.email_verified_at', null);
    }

    public function test_blocking_revokes_tokens_and_protects_admins(): void
    {
        $user = User::factory()->create();
        $user->createToken('old-session');
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/users/'.$user->id.'/block')->assertOk();
        $this->assertTrue($user->fresh()->is_blocked);
        $this->assertSame(0, $user->tokens()->count());
        $this->deleteJson('/api/v1/admin/users/'.$user->id.'/block')->assertNoContent();
        $this->assertFalse($user->fresh()->is_blocked);
        $this->putJson('/api/v1/admin/users/'.$admin->id.'/block')->assertStatus(409);
    }

    public function test_unpublished_cover_is_available_only_to_owner_or_unblocked_admin(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('covers/pending.png', 'cover');
        $owner = User::factory()->artist()->create();
        $track = Track::factory()->pending()->create(['user_id' => $owner->id, 'cover_path' => 'covers/pending.png']);
        $url = '/api/v1/tracks/'.$track->id.'/cover';
        $this->get($url)->assertNotFound();
        Sanctum::actingAs(User::factory()->create());
        $this->get($url)->assertNotFound();
        Sanctum::actingAs($owner);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->get($url)->assertOk();
        Sanctum::actingAs(User::factory()->admin()->blocked()->create());
        $this->get($url)->assertNotFound();
    }

    public function test_otp_keeps_the_stored_birth_date_and_password_and_code_is_single_use(): void
    {
        Mail::fake();
        $service = app(EmailOtpService::class);
        $service->requestCode('otp-new@example.com', 'OTP', 'listener', '2000-01-01', Hash::make('Secret123!'));
        $record = EmailVerificationCode::where('email', 'otp-new@example.com')->firstOrFail();
        $this->assertStringNotContainsString('Secret123!', $record->payload);
        $record->update(['code_hash' => Hash::make('123456')]);
        $this->postJson('/api/v1/auth/email/verify-code', ['email' => 'otp-new@example.com', 'code' => '123456', 'birth_date' => '2026-01-01'])
            ->assertCreated()->assertJsonPath('new_account', true);
        $user = User::where('email', 'otp-new@example.com')->firstOrFail();
        $this->assertSame('2000-01-01', $user->birth_date->toDateString());
        $this->assertTrue(Hash::check('Secret123!', $user->password));
        $this->postJson('/api/v1/auth/email/verify-code', ['email' => $user->email, 'code' => '123456'])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Secret123!'])->assertOk();
    }

    public function test_moderation_queue_includes_uploader_id_without_private_birthday(): void
    {
        $track = Track::factory()->pending()->create();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/tracks')->assertOk()->assertJsonPath('data.0.user_id', $track->user_id)
            ->assertJsonPath('data.0.artist.id', $track->user_id)->assertJsonMissingPath('data.0.artist.birth_date');
    }
}
