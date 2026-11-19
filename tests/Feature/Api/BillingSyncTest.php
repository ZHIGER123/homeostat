<?php
namespace Tests\Feature\Api;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingSyncTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => 'sk_test_fixture', 'services.stripe.webhook_secret' => 'whsec_fixture', 'plans.artist.price_id_monthly' => 'price_artist', 'plans.artist_pro.price_id_monthly' => 'price_pro']);
    }
    private function stripeSession(User $user, array $overrides = []): array
    {
        return array_merge(['id'=>'cs_test_fixture', 'mode'=>'subscription', 'status'=>'complete', 'payment_status'=>'paid', 'subscription'=>'sub_fixture', 'customer'=>'cus_fixture', 'metadata'=>['user_id'=>(string) $user->id, 'plan'=>'artist_pro']], $overrides);
    }
    private function stripeSubscription(User $user, array $overrides = []): array
    {
        return array_merge(['id'=>'sub_fixture', 'customer'=>'cus_fixture', 'status'=>'active', 'metadata'=>['user_id'=>(string) $user->id, 'plan'=>'artist_pro'], 'items'=>['data'=>[['price'=>['id'=>'price_pro'], 'current_period_start'=>now()->timestamp, 'current_period_end'=>now()->addMonth()->timestamp]]]], $overrides);
    }
    public function test_verified_payment_activates_the_correct_plan_and_unlimited_quota(): void
    {
        $user = User::factory()->artist()->create(); Sanctum::actingAs($user);
        Http::fake(['*/v1/checkout/sessions/cs_test_fixture' => Http::response($this->stripeSession($user)), '*/v1/subscriptions/sub_fixture' => Http::response($this->stripeSubscription($user))]);
        $this->postJson('/api/v1/billing/sync', ['session_id'=>'cs_test_fixture'])->assertOk()->assertJsonPath('data.plan', 'artist_pro')->assertJsonPath('data.usage.unlimited', true);
        $this->assertDatabaseHas('subscriptions', ['user_id'=>$user->id, 'provider_subscription_id'=>'sub_fixture', 'plan'=>'artist_pro', 'status'=>'active']);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.plan', 'artist_pro');
    }
    public function test_unpaid_or_other_users_session_cannot_activate_access(): void
    {
        $user = User::factory()->artist()->create(); $other = User::factory()->artist()->create(); Sanctum::actingAs($user);
        Http::fake(['*/v1/checkout/sessions/cs_test_other' => Http::response($this->stripeSession($other, ['id'=>'cs_test_other'])), '*/v1/checkout/sessions/cs_test_unpaid' => Http::response($this->stripeSession($user, ['id'=>'cs_test_unpaid', 'payment_status'=>'unpaid']))]);
        $this->postJson('/api/v1/billing/sync', ['session_id'=>'cs_test_other'])->assertForbidden();
        $this->postJson('/api/v1/billing/sync', ['session_id'=>'cs_test_unpaid'])->assertStatus(409);
        $this->assertDatabaseCount('subscriptions', 0);
    }
    public function test_unknown_price_never_grants_a_plan(): void
    {
        $user = User::factory()->artist()->create(); Sanctum::actingAs($user);
        $sub = $this->stripeSubscription($user, ['items'=>['data'=>[['price'=>['id'=>'price_wrong']]]]]);
        Http::fake(['*/v1/checkout/sessions/cs_test_fixture'=>Http::response($this->stripeSession($user)), '*/v1/subscriptions/sub_fixture'=>Http::response($sub)]);
        $this->postJson('/api/v1/billing/sync', ['session_id'=>'cs_test_fixture'])->assertUnprocessable();
        $this->assertDatabaseCount('subscriptions', 0);
    }
    public function test_missing_webhook_can_be_recovered_without_another_purchase(): void
    {
        $user = User::factory()->artist()->create(); $other = User::factory()->artist()->create(); Sanctum::actingAs($user);
        Http::fake(['*/v1/checkout/sessions?*'=>Http::response(['data'=>[$this->stripeSession($other), $this->stripeSession($user)], 'has_more'=>false]), '*/v1/subscriptions/sub_fixture'=>Http::response($this->stripeSubscription($user))]);
        $this->postJson('/api/v1/billing/sync')->assertOk()->assertJsonPath('data.plan', 'artist_pro');
        $this->assertDatabaseCount('subscriptions', 1);
    }
    public function test_prevents_repeated_checkout_and_does_not_replace_active_subscription(): void
    {
        $user = User::factory()->artist()->create(); Sanctum::actingAs($user);
        Subscription::create(['user_id'=>$user->id,'provider'=>'stripe','provider_customer_id'=>'cus_old','provider_subscription_id'=>'sub_old','plan'=>'artist','status'=>'active']);
        $this->postJson('/api/v1/billing/checkout', ['plan'=>'artist_pro'])->assertStatus(409);
        Http::fake(['*/v1/checkout/sessions/cs_test_fixture'=>Http::response($this->stripeSession($user)), '*/v1/subscriptions/sub_fixture'=>Http::response($this->stripeSubscription($user))]);
        $this->postJson('/api/v1/billing/sync', ['session_id'=>'cs_test_fixture'])->assertStatus(409);
        $this->assertDatabaseHas('subscriptions', ['user_id'=>$user->id, 'provider_subscription_id'=>'sub_old']);
    }
    public function test_signed_webhook_retrieves_current_subscription_and_revokes_access(): void
    {
        $user = User::factory()->artist()->create();
        Subscription::create(['user_id'=>$user->id,'provider'=>'stripe','provider_customer_id'=>'cus_fixture','provider_subscription_id'=>'sub_fixture','plan'=>'artist_pro','status'=>'active']);
        Http::fake(['*/v1/subscriptions/sub_fixture'=>Http::response($this->stripeSubscription($user, ['status'=>'canceled']))]);
        $payload=json_encode(['id'=>'evt_fixture','type'=>'customer.subscription.deleted','data'=>['object'=>['id'=>'sub_fixture']]], JSON_THROW_ON_ERROR);
        $time=time(); $signature='t='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_fixture');
        $this->call('POST','/api/v1/billing/webhook',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_STRIPE_SIGNATURE'=>$signature],$payload)->assertOk();
        $this->assertSame('free', $user->fresh()->currentPlan());
    }
}
