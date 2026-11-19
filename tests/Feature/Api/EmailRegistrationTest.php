<?php

namespace Tests\Feature\Api;

use App\Mail\VerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\EmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        $challenge = $this->getJson('/api/v1/auth/captcha')->assertOk()->json();
        preg_match('/(\d+)\s*\+\s*(\d+)/u', $challenge['question'], $parts);
        return ['name' => 'Новый', 'email' => 'verify@example.com', 'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!', 'birth_date' => '2000-01-01',
            'captcha_id' => $challenge['id'], 'captcha_answer' => (string) ((int) $parts[1] + (int) $parts[2])];
    }

    public function test_registration_delivers_real_code_to_mailer_and_only_then_creates_verified_account(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/register', $this->data())->assertStatus(202)
            ->assertJsonPath('verification_required', true)->assertJsonMissingPath('token');
        $this->assertDatabaseMissing('users', ['email' => 'verify@example.com']);
        $code = '';
        Mail::assertSent(VerificationCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;
            return $mail->hasTo('verify@example.com') && strlen($code) === 6;
        });
        $record = EmailVerificationCode::where('email', 'verify@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($code, $record->code_hash));
        $this->postJson('/api/v1/auth/email/verify-code', ['email' => 'verify@example.com', 'code' => $code])
            ->assertCreated()->assertJsonPath('new_account', true)->assertJsonStructure(['token', 'user']);
        $user = User::where('email', 'verify@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Secret123!', $user->password));
        $this->postJson('/api/v1/auth/email/verify-code', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
    }

    public function test_resending_is_rate_limited_and_does_not_create_account(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/email/request-code', $this->data())->assertStatus(202);
        $this->postJson('/api/v1/auth/email/request-code', $this->data())->assertStatus(429);
        Mail::assertSent(VerificationCodeMail::class, 1);
        $this->assertDatabaseMissing('users', ['email' => 'verify@example.com']);
    }

    public function test_expired_code_is_rejected(): void
    {
        EmailVerificationCode::create(['email' => 'expired@example.com', 'purpose' => 'registration',
            'code_hash' => Hash::make('123456'), 'payload' => '{}', 'expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/auth/email/verify-code', ['email' => 'expired@example.com', 'code' => '123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseMissing('users', ['email' => 'expired@example.com']);
    }

    public function test_attempt_limit_invalidates_the_code(): void
    {
        config(['services.email_otp.max_attempts' => 2]);
        $record = EmailVerificationCode::create(['email' => 'attempts@example.com', 'purpose' => 'registration',
            'code_hash' => Hash::make('123456'), 'payload' => '{}', 'expires_at' => now()->addMinutes(10)]);
        foreach (range(1, 2) as $attempt) {
            $this->postJson('/api/v1/auth/email/verify-code', ['email' => $record->email, 'code' => '654321'])->assertUnprocessable();
        }
        $this->assertNotNull($record->fresh()->used_at);
        $this->postJson('/api/v1/auth/email/verify-code', ['email' => $record->email, 'code' => '123456'])->assertUnprocessable();
    }

    public function test_delivery_failure_does_not_report_success_or_leave_a_usable_code(): void
    {
        Mail::shouldReceive('to')->once()->with('verify@example.com')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->postJson('/api/v1/register', $this->data())->assertStatus(503)->assertJsonMissingPath('token');
        $this->assertNotNull(EmailVerificationCode::where('email', 'verify@example.com')->firstOrFail()->used_at);
        $this->assertDatabaseMissing('users', ['email' => 'verify@example.com']);
    }
}
