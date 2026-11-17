<?php

namespace Tests\Feature\Api;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModerationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_queue_shows_pending_tracks_by_default_and_filters_by_status(): void
    {
        Track::factory()->count(2)->pending()->create();
        Track::factory()->published()->create();
        Track::factory()->rejected()->create();

        $this->getJson('/api/v1/admin/tracks')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/tracks?status=rejected')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/tracks?status=unknown')->assertStatus(422);
    }

    public function test_admin_can_approve_pending_track(): void
    {
        $track = Track::factory()->pending()->create();

        $this->patchJson("/api/v1/admin/tracks/{$track->id}/status", ['status' => 'published'])
            ->assertOk()->assertJsonPath('data.status', 'published');

        $this->getJson('/api/v1/tracks')->assertJsonCount(1, 'data');
    }

    public function test_reject_requires_reason_and_stores_it(): void
    {
        $track = Track::factory()->pending()->create();

        $this->patchJson("/api/v1/admin/tracks/{$track->id}/status", ['status' => 'rejected'])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->patchJson("/api/v1/admin/tracks/{$track->id}/status", ['status' => 'rejected', 'reason' => 'Низкое качество'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reject_reason', 'Низкое качество');
    }

    public function test_transition_is_allowed_only_from_pending(): void
    {
        $published = Track::factory()->published()->create();

        $this->patchJson("/api/v1/admin/tracks/{$published->id}/status", ['status' => 'rejected', 'reason' => 'x'])
            ->assertStatus(409);
        $this->patchJson('/api/v1/admin/tracks/999999/status', ['status' => 'published'])->assertNotFound();
        $this->patchJson("/api/v1/admin/tracks/{$published->id}/status", ['status' => 'pending'])->assertStatus(422);
    }
}
