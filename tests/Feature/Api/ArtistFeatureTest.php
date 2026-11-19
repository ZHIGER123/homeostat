<?php
namespace Tests\Feature\Api;

use App\Models\Subscription;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArtistFeatureTest extends TestCase
{
    use RefreshDatabase;
    private function paid(User $user, string $plan): Subscription
    {
        return Subscription::create(['user_id'=>$user->id,'provider'=>'stripe','provider_subscription_id'=>'sub_test'.$user->id,'plan'=>$plan,'status'=>'active','current_period_end'=>now()->addMonth()]);
    }
    public function test_spotlight_limits_and_ownership_are_enforced(): void
    {
        $artist = User::factory()->artist()->create(); $tracks = Track::factory()->count(6)->create(['user_id'=>$artist->id]);
        Sanctum::actingAs($artist);
        $this->putJson('/api/v1/tracks/'.$tracks[0]->id.'/spotlight',['enabled'=>true])->assertOk();
        $this->putJson('/api/v1/tracks/'.$tracks[1]->id.'/spotlight',['enabled'=>true])->assertStatus(409);
        $this->paid($artist,'artist');
        foreach ($tracks->slice(1,4) as $track) $this->putJson('/api/v1/tracks/'.$track->id.'/spotlight',['enabled'=>true])->assertOk();
        $this->putJson('/api/v1/tracks/'.$tracks[5]->id.'/spotlight',['enabled'=>true])->assertStatus(409);
        $this->getJson('/api/v1/users/'.$artist->id.'/spotlight')->assertOk()->assertJsonCount(5,'data');
        Sanctum::actingAs(User::factory()->create());
        $this->putJson('/api/v1/tracks/'.$tracks[0]->id.'/spotlight',['enabled'=>false])->assertForbidden();
    }
    public function test_artist_boost_is_limited_to_two_activations_per_month_and_pro_is_unlimited(): void
    {
        $artist=User::factory()->artist()->create(); $tracks=Track::factory()->count(3)->create(['user_id'=>$artist->id]); Sanctum::actingAs($artist);
        $url=fn($track)=>'/api/v1/tracks/'.$track->id.'/boost';
        $this->putJson($url($tracks[0]),['enabled'=>true])->assertForbidden();
        $subscription=$this->paid($artist,'artist');
        $this->putJson($url($tracks[0]),['enabled'=>true])->assertOk();
        $this->putJson($url($tracks[0]),['enabled'=>true])->assertOk();
        $this->putJson($url($tracks[1]),['enabled'=>true])->assertOk();
        $this->putJson($url($tracks[2]),['enabled'=>true])->assertStatus(409);
        $subscription->update(['plan'=>'artist_pro']);
        $this->putJson($url($tracks[2]),['enabled'=>true])->assertOk();
        $this->assertDatabaseCount('artist_feature_usage',3);
    }
    public function test_paid_author_can_disable_comments_and_reenable_them_after_downgrade(): void
    {
        $artist=User::factory()->artist()->create(); $track=Track::factory()->create(['user_id'=>$artist->id]); Sanctum::actingAs($artist);
        $url='/api/v1/tracks/'.$track->id.'/comments-settings';
        $this->putJson($url,['enabled'=>false])->assertForbidden();
        $subscription=$this->paid($artist,'artist');
        $this->putJson($url,['enabled'=>false])->assertOk();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/tracks/'.$track->id.'/comments',['body'=>'Хороший трек'])->assertStatus(409);
        $subscription->update(['status'=>'canceled']); Sanctum::actingAs($artist);
        $this->putJson($url,['enabled'=>true])->assertOk();
    }
    public function test_boost_stops_affecting_order_after_subscription_expires(): void
    {
        $artist=User::factory()->artist()->create(); $this->paid($artist,'artist');
        $boosted=Track::factory()->create(['user_id'=>$artist->id,'created_at'=>now()->subDay(), 'is_boosted'=>true]);
        $new=Track::factory()->create(['created_at'=>now()]);
        $this->getJson('/api/v1/tracks')->assertJsonPath('data.0.id',$boosted->id);
        $artist->subscription()->update(['status'=>'canceled']);
        $this->getJson('/api/v1/tracks')->assertJsonPath('data.0.id',$new->id);
    }
}
