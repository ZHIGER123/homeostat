<?php

namespace Tests\Feature\Api;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArtistSubscriptionAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_and_admin_cannot_purchase_sync_resume_or_open_paid_portal(): void
    {
        Http::fake();
        foreach (['listener', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]); Sanctum::actingAs($user);
            $this->postJson('/api/v1/billing/checkout', ['plan' => 'artist'])->assertForbidden();
            $this->postJson('/api/v1/billing/sync')->assertForbidden();
            $this->postJson('/api/v1/billing/resume')->assertForbidden();
            $this->postJson('/api/v1/billing/portal')->assertForbidden();
            $this->assertSame('free', $user->fresh()->currentPlan());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_artist_can_create_checkout_and_its_link_is_recorded_for_safe_deletion(): void
    {
        config(['services.stripe.secret' => 'sk_test_fixture', 'plans.artist.price_id_monthly' => 'price_artist']);
        $user = User::factory()->artist()->create(); Sanctum::actingAs($user);
        Http::fake(['*/checkout/sessions' => Http::response(['id' => 'cs_test_artist', 'url' => 'https://checkout.stripe.com/test'])]);
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'artist'])->assertOk()->assertJsonPath('data.plan', 'artist');
        $this->assertDatabaseHas('billing_checkout_sessions', ['user_id' => $user->id, 'session_id' => 'cs_test_artist']);
    }

    public function test_legacy_paid_subscription_never_grants_benefits_to_listener(): void
    {
        $user = User::factory()->listener()->create();
        Subscription::create(['user_id' => $user->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_legacy', 'plan' => 'artist_pro', 'status' => 'active']);
        $this->assertSame('free', $user->fresh()->currentPlan());
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/billing/me')->assertOk()->assertJsonPath('data.plan', 'free')->assertJsonPath('data.usage.unlimited', false);
    }

    public function test_active_artist_cannot_be_demoted_before_subscription_is_cancelled(): void
    {
        $artist = User::factory()->artist()->create();
        Subscription::create(['user_id' => $artist->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_active', 'plan' => 'artist', 'status' => 'active']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson('/api/v1/admin/users/'.$artist->id, ['role' => 'listener'])->assertStatus(409);
        $this->assertSame('artist', $artist->fresh()->role->value);
    }
}
