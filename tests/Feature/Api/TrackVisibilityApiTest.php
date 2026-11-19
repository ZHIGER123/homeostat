<?php

namespace Tests\Feature\Api;

use App\Models\Comment;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackVisibilityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }


    public function test_owner_can_open_the_pending_track_returned_by_upload(): void
    {
        $owner = User::factory()->artist()->create();
        $genre = Genre::factory()->create();
        Sanctum::actingAs($owner);

        $response = $this->post('/api/v1/tracks', [
            'title' => 'Uploaded from the studio',
            'genre_id' => $genre->id,
            'duration' => 120,
            'audio' => \Tests\Support\TestAudio::wav(),
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $trackId = $response->json('data.id');
        $this->getJson("/api/v1/tracks/{$trackId}")
            ->assertOk()
            ->assertJsonPath('data.id', $trackId)
            ->assertJsonPath('data.status', 'pending');
        $this->getJson("/api/v1/tracks/{$trackId}/comments")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

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
