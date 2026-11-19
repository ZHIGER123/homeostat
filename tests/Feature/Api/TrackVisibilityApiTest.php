<?php

namespace Tests\Feature\Api;

use App\Models\Comment;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackVisibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_track_and_comments_are_visible_only_to_owner_and_admin(): void
    {
        $owner = User::factory()->artist()->create();
        $otherUser = User::factory()->listener()->create();
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->pending()->create(['user_id' => $owner->id]);
        Comment::factory()->create(['track_id' => $track->id, 'user_id' => $owner->id]);

        $this->getJson("/api/v1/tracks/{$track->id}")->assertNotFound();
        $this->getJson("/api/v1/tracks/{$track->id}/comments")->assertNotFound();

        Sanctum::actingAs($otherUser);
        $this->getJson("/api/v1/tracks/{$track->id}")->assertNotFound();
        $this->getJson("/api/v1/tracks/{$track->id}/comments")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $track->id)
            ->assertJsonPath('data.status', 'pending');
        $this->getJson("/api/v1/tracks/{$track->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/tracks/{$track->id}")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson("/api/v1/tracks/{$track->id}/comments")->assertOk()->assertJsonCount(1, 'data');
    }
}
