<?php

namespace Tests\Feature\Api;

use App\Models\EmailVerificationCode;
use App\Services\RegistrationMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationMailServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'resend', 'services.resend.key' => 're_test_only', 'mail.from.address' => 'noreply@verified.example']);
        Http::preventStrayRequests();
    }

    private function data(): array
    {
        $challenge = $this->getJson('/api/v1/auth/captcha')->assertOk()->json();
        preg_match('/(\d+)\s*\+\s*(\d+)/u', $challenge['question'], $parts);
        return ['name' => 'Тест', 'email' => 'recipient@example.com', 'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!', 'birth_date' => '2000-01-01',
            'captcha_id' => $challenge['id'], 'captcha_answer' => (string) ((int) $parts[1] + (int) $parts[2])];
    }

    public function test_registration_sends_code_through_resend_and_only_correct_code_confirms_account(): void
    {
        Http::fake(['api.resend.com/emails' => Http::response(['id' => 'test-email-id'], 200)]);
        $this->postJson('/api/v1/auth/email/request-code', $this->data())->assertStatus(202)->assertJsonStructure(['resend_token']);
        $this->assertDatabaseMissing('users', ['email' => 'recipient@example.com']);
        $code = '';
        Http::assertSent(function ($request) use (&$code) {
            preg_match('/\b(\d{6})\b/', $request['text'], $matches);
            $code = $matches[1] ?? '';
            return $request->url() === 'https://api.resend.com/emails'
                && $request->hasHeader('Authorization', 'Bearer re_test_only')
                && $request['from'] === 'noreply@verified.example'
                && $request['to'] === ['recipient@example.com']
                && strlen($code) === 6;
        });
        $record = EmailVerificationCode::where('email', 'recipient@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($code, $record->code_hash));
        $this->postJson('/api/v1/auth/email/verify-code', ['email' => $record->email, 'code' => $code])->assertCreated()->assertJsonPath('new_account', true);
        $this->assertDatabaseHas('users', ['email' => $record->email]);
    }

    public function test_missing_key_never_reports_success_or_contacts_the_provider(): void
    {
        config(['services.resend.key' => '']);
        Http::fake();
        $this->postJson('/api/v1/auth/email/request-code', $this->data())->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('email_verification_codes', 0);
    }

    public function test_provider_failure_invalidates_new_code_and_does_not_create_account(): void
    {
        Http::fake(['api.resend.com/emails' => Http::response(['message' => 'Domain not verified'], 403)]);
        $this->postJson('/api/v1/auth/email/request-code', $this->data())->assertStatus(503);
        $this->assertNotNull(EmailVerificationCode::where('email', 'recipient@example.com')->firstOrFail()->used_at);
        $this->assertDatabaseMissing('users', ['email' => 'recipient@example.com']);
    }

    public function test_resend_token_allows_resending_after_cooldown_without_a_second_captcha(): void
    {
        Http::fake(['api.resend.com/emails' => Http::response(['id' => 'test-email-id'], 200)]);
        $data = $this->data();
        $response = $this->postJson('/api/v1/auth/email/request-code', $data)->assertStatus(202);
        unset($data['captcha_id'], $data['captcha_answer']);
        $data['resend_token'] = $response->json('resend_token');
        $this->postJson('/api/v1/auth/email/request-code', $data)->assertStatus(429);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/email/request-code', $data)->assertStatus(202);
        Http::assertSentCount(2);
        $this->assertDatabaseMissing('users', ['email' => 'recipient@example.com']);
    }

    public function test_resend_token_cannot_bypass_captcha_for_another_email(): void
    {
        Http::fake(['api.resend.com/emails' => Http::response(['id' => 'test-email-id'], 200)]);
        $data = $this->data();
        $token = $this->postJson('/api/v1/auth/email/request-code', $data)->assertStatus(202)->json('resend_token');
        unset($data['captcha_id'], $data['captcha_answer']);
        $data['email'] = 'another@example.com';
        $data['resend_token'] = $token;
        $this->postJson('/api/v1/auth/email/request-code', $data)->assertStatus(422);
        Http::assertSentCount(1);
    }
}
