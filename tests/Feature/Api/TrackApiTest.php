<?php

namespace Tests\Feature\Api;

use App\Enums\TrackStatus;
use App\Models\Genre;
use App\Models\Like;
use App\Models\Track;
use App\Models\User;
use App\Services\TrackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function payload(User $artist, Genre $genre, array $override = []): array
    {
        return $override + [
            'user_id' => $artist->id,
            'genre_id' => $genre->id,
            'title' => 'Night Drive',
            'description' => 'Synthwave',
            'audio' => \Tests\Support\TestAudio::wav(),
            'duration' => 214,
        ];
    }

    public function test_index_returns_only_published_tracks_with_pagination(): void
    {
        Track::factory()->count(25)->published()->create();
        Track::factory()->pending()->create();
        Track::factory()->rejected()->create();

        $this->getJson('/api/v1/tracks')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonMissingPath('data.0.audio_path');
    }

    public function test_index_searches_by_title_and_artist_name_and_filters_by_genre(): void
    {
        $nova = User::factory()->artist()->create(['name' => 'Nova Beats']);
        $rock = Genre::factory()->create();
        Track::factory()->published()->create(['title' => 'Neon Rain', 'user_id' => $nova->id]);
        Track::factory()->published()->create(['title' => 'Other', 'genre_id' => $rock->id]);

        $this->getJson('/api/v1/tracks?q=Neon')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tracks?q=Nova')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tracks?genre_id='.$rock->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Other');
    }

    public function test_index_sorts_by_likes_count(): void
    {
        $popular = Track::factory()->published()->create(['title' => 'Popular']);
        Track::factory()->published()->create(['title' => 'Quiet']);
        Like::factory()->count(3)->create(['track_id' => $popular->id]);

        $this->getJson('/api/v1/tracks?sort=likes_count&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Popular')
            ->assertJsonPath('data.0.likes_count', 3);
    }

    public function test_index_rejects_invalid_query_parameters(): void
    {
        $this->getJson('/api/v1/tracks?sort=password&per_page=1000')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort', 'per_page']);
    }

    public function test_show_returns_track_card_and_404_for_unknown_id(): void
    {
        $track = Track::factory()->published()->create();

        $this->getJson('/api/v1/tracks/'.$track->id)
            ->assertOk()
            ->assertJsonPath('data.id', $track->id)
            ->assertJsonPath('data.artist.id', $track->user_id);

        $this->getJson('/api/v1/tracks/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Ресурс не найден.']);
    }

    public function test_store_creates_pending_track_and_returns_201(): void
    {
        $artist = User::factory()->artist()->create();
        Sanctum::actingAs($artist);
        $genre = Genre::factory()->create();

        $this->postJson('/api/v1/tracks', $this->payload($artist, $genre))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.title', 'Night Drive');

        $this->assertDatabaseHas('tracks', ['title' => 'Night Drive', 'status' => 'pending', 'plays_count' => 0]);
    }

    public function test_store_ignores_client_supplied_status(): void
    {
        $artist = User::factory()->artist()->create();
        Sanctum::actingAs($artist);
        $genre = Genre::factory()->create();

        $this->postJson('/api/v1/tracks', $this->payload($artist, $genre, ['status' => 'published', 'plays_count' => 999]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.plays_count', 0);
    }

    public function test_store_validates_input_with_422(): void
    {
        Sanctum::actingAs(User::factory()->artist()->create());
        $this->postJson('/api/v1/tracks', ['title' => str_repeat('a', 151), 'duration' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['genre_id', 'title', 'audio', 'duration'])
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_first_upload_promotes_listener_but_blocked_artist_is_forbidden(): void
    {
        $genre = Genre::factory()->create();
        $listener = User::factory()->listener()->create();
        $blocked = User::factory()->artist()->blocked()->create();

        Sanctum::actingAs($listener);
        $this->postJson('/api/v1/tracks', $this->payload($listener, $genre))->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.artist.role', 'artist');
        $this->assertSame('artist', $listener->fresh()->role->value);
        Sanctum::actingAs($blocked);
        $this->postJson('/api/v1/tracks', $this->payload($blocked, $genre))->assertForbidden();
    }

    public function test_store_returns_409_when_pending_limit_exceeded(): void
    {
        $artist = User::factory()->artist()->create();
        Sanctum::actingAs($artist);
        $genre = Genre::factory()->create();
        Track::factory()->count(TrackService::MAX_PENDING_PER_ARTIST)->pending()
            ->create(['user_id' => $artist->id, 'genre_id' => $genre->id]);

        $this->postJson('/api/v1/tracks', $this->payload($artist, $genre))->assertStatus(409);
    }

    public function test_update_changes_fields_and_sends_published_track_back_to_moderation(): void
    {
        $track = Track::factory()->published()->create(['title' => 'Old']);

        $this->patchJson('/api/v1/tracks/'.$track->id, ['title' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New')
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_update_without_changes_keeps_status_and_rejected_goes_to_pending(): void
    {
        $published = Track::factory()->published()->create(['title' => 'Same']);
        $this->putJson('/api/v1/tracks/'.$published->id, ['title' => 'Same'])
            ->assertOk()->assertJsonPath('data.status', 'published');

        $rejected = Track::factory()->rejected()->create();
        $this->patchJson('/api/v1/tracks/'.$rejected->id, ['title' => 'Fixed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.reject_reason', null);
    }

    public function test_update_cannot_change_status_directly(): void
    {
        $track = Track::factory()->pending()->create();

        $this->patchJson('/api/v1/tracks/'.$track->id, ['status' => 'published'])->assertOk();

        $this->assertSame(TrackStatus::Pending, $track->fresh()->status);
    }

    public function test_update_validates_and_returns_404_for_unknown_track(): void
    {
        $track = Track::factory()->create();

        $this->patchJson('/api/v1/tracks/'.$track->id, ['genre_id' => 9999, 'duration' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors(['genre_id', 'duration']);
        $this->patchJson('/api/v1/tracks/999999', ['title' => 'x'])->assertNotFound();
    }

    public function test_destroy_returns_204_and_removes_related_records(): void
    {
        $track = Track::factory()->published()->create();
        Like::factory()->create(['track_id' => $track->id]);

        $this->deleteJson('/api/v1/tracks/'.$track->id)->assertNoContent();

        $this->assertDatabaseMissing('tracks', ['id' => $track->id]);
        $this->assertDatabaseCount('likes', 0);
        $this->deleteJson('/api/v1/tracks/'.$track->id)->assertNotFound();
    }

    public function test_play_counts_once_per_30_seconds_per_client(): void
    {
        $track = Track::factory()->published()->create(['plays_count' => 10]);

        $this->postJson("/api/v1/tracks/{$track->id}/play")
            ->assertOk()->assertJson(['counted' => true, 'plays_count' => 11]);
        $this->postJson("/api/v1/tracks/{$track->id}/play")
            ->assertOk()->assertJson(['counted' => false, 'plays_count' => 11]);

        $this->assertSame(11, $track->fresh()->plays_count);
    }

    public function test_play_of_unpublished_track_returns_404(): void
    {
        $track = Track::factory()->pending()->create();

        $this->postJson("/api/v1/tracks/{$track->id}/play")->assertNotFound();
    }
}
