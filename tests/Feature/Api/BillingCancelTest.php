<?php
namespace Tests\Feature\Api;

use App\Models\Subscription;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingCancelTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(): array
    {
        config(['services.stripe.secret'=>'sk_test_fixture', 'plans.artist_pro.price_id_monthly'=>'price_pro']);
        $user=User::factory()->artist()->create();
        $subscription=Subscription::create(['user_id'=>$user->id,'provider'=>'stripe','provider_customer_id'=>'cus_test','provider_subscription_id'=>'sub_test','plan'=>'artist_pro','status'=>'active','current_period_end'=>now()->addMonth()]);
        $tracks=Track::factory()->count(3)->create(['user_id'=>$user->id]);
        foreach ($tracks as $track) $track->forceFill(['is_spotlight'=>true,'is_boosted'=>true,'comments_enabled'=>false])->save();
        return [$user,$subscription,$tracks];
    }
    private function stripe(User $user, string $status): array
    {
        return ['id'=>'sub_test','customer'=>'cus_test','status'=>$status,'metadata'=>['user_id'=>(string)$user->id],'items'=>['data'=>[['price'=>['id'=>'price_pro'],'current_period_end'=>now()->addMonth()->timestamp]]]];
    }
    public function test_immediate_cancellation_only_after_stripe_confirms_and_restores_free_features(): void
    {
        [$user,$subscription,$tracks]=$this->fixture(); Sanctum::actingAs($user);
        Http::fake(['*/v1/subscriptions/sub_test'=>Http::response($this->stripe($user,'canceled'))]);
        $this->postJson('/api/v1/billing/cancel')->assertOk()->assertJsonPath('data.status','canceled');
        Http::assertSent(fn($request)=>$request->method()==='DELETE' && str_ends_with($request->url(),'/v1/subscriptions/sub_test'));
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.plan','free');
        $this->getJson('/api/v1/billing/me')->assertOk()->assertJsonPath('data.plan','free')->assertJsonPath('data.usage.unlimited',false);
        $this->assertSame(3,$user->tracks()->count());
        $this->assertSame(1,$user->tracks()->where('is_spotlight',true)->count());
        $this->assertSame(0,$user->tracks()->where('is_boosted',true)->count());
        $this->assertSame(0,$user->tracks()->where('comments_enabled',false)->count());
        $this->postJson('/api/v1/billing/resume')->assertStatus(409);
    }
    public function test_stripe_failure_leaves_local_subscription_and_features_intact(): void
    {
        [$user]=$this->fixture(); Sanctum::actingAs($user);
        Http::fake(['*/v1/subscriptions/sub_test'=>Http::response(['error'=>['message'=>'failed']],500)]);
        $this->postJson('/api/v1/billing/cancel')->assertStatus(500);
        $this->assertSame('artist_pro',$user->fresh()->currentPlan());
        $this->assertSame(3,$user->tracks()->where('is_boosted',true)->count());
    }
    public function test_manual_sync_recovers_a_missed_cancellation_without_enabling_the_old_plan(): void
    {
        [$user]=$this->fixture(); Sanctum::actingAs($user);
        Http::fake(['*/v1/subscriptions/sub_test'=>Http::response($this->stripe($user,'canceled')), '*/v1/checkout/sessions?*'=>Http::response(['data'=>[],'has_more'=>false])]);
        $this->postJson('/api/v1/billing/sync')->assertOk()->assertJsonPath('data.plan','free');
        $this->assertSame(0,$user->tracks()->where('comments_enabled',false)->count());
    }
}
