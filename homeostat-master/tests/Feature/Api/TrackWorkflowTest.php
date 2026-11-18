<?php

namespace Tests\Feature\Api;

use App\Enums\TrackStatus;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function uploadData(Genre $genre): array
    {
        return ['title' => 'Новый трек', 'genre_id' => $genre->id, 'duration' => 120,
            'audio' => UploadedFile::fake()->create('song.mp3', 100, 'audio/mpeg')];
    }

    public function test_upload_moderation_catalog_and_stream_form_one_scenario(): void
    {
        $artist = User::factory()->artist()->create();
        $genre = Genre::factory()->create();
        Sanctum::actingAs($artist);
        $response = $this->postJson('/api/v1/tracks', $this->uploadData($genre))->assertCreated()->assertJsonPath('data.status', 'pending');
        $track = Track::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($track->audio_path);
        $this->assertEquals($artist->id, $track->user_id);
        $this->getJson('/api/v1/tracks')->assertJsonCount(0, 'data');
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson('/api/v1/admin/tracks/'.$track->id.'/status', ['status' => 'published'])->assertOk()->assertJsonPath('data.status', 'published');
        $this->getJson('/api/v1/tracks')->assertJsonCount(1, 'data');
        $this->get('/api/v1/tracks/'.$track->id.'/stream')->assertOk();
        // UploadedFile::fake создаёт пустой временный файл; диапазон проверяем на непустом содержимом.
        Storage::disk('local')->put($track->audio_path, 'test-audio-content');
        $this->get('/api/v1/tracks/'.$track->id.'/stream', ['Range' => 'bytes=0-4'])->assertStatus(206);
        Sanctum::actingAs($artist);
        $this->deleteJson('/api/v1/tracks/'.$track->id)->assertNoContent();
        Storage::disk('local')->assertMissing($track->audio_path);
    }

    public function test_invalid_file_and_missing_genre_are_validation_errors(): void
    {
        Sanctum::actingAs(User::factory()->artist()->create());
        $this->postJson('/api/v1/tracks', ['title' => 'T', 'genre_id' => 999999, 'duration' => 1,
            'audio' => UploadedFile::fake()->create('bad.txt', 1, 'text/plain')])
            ->assertStatus(422)->assertJsonValidationErrors(['audio', 'genre_id']);
    }

    public function test_failed_creation_rolls_back_and_deletes_uploaded_files(): void
    {
        config(['app.debug' => false]);
        Sanctum::actingAs(User::factory()->artist()->create());
        $genre = Genre::factory()->create();
        Event::listen('eloquent.creating: '.Track::class, fn () => throw new \RuntimeException('internal failure'));
        $this->postJson('/api/v1/tracks', $this->uploadData($genre))->assertStatus(500)->assertDontSee('internal failure');
        $this->assertDatabaseCount('tracks', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('tracks'));
    }

    public function test_cannot_move_published_track_back_to_pending_when_limit_reached(): void
    {
        $artist = User::factory()->artist()->create();
        Track::factory()->count(10)->pending()->create(['user_id' => $artist->id]);
        $track = Track::factory()->published()->create(['user_id' => $artist->id, 'title' => 'Old']);
        Sanctum::actingAs($artist);
        $this->patchJson('/api/v1/tracks/'.$track->id, ['title' => 'New'])->assertStatus(409)->assertJsonStructure(['message']);
        $this->assertSame(TrackStatus::Published, $track->fresh()->status);
        $this->assertSame('Old', $track->fresh()->title);
    }

    public function test_catalog_filters_two_fields_and_paginates_with_query(): void
    {
        $genre = Genre::factory()->create();
        Track::factory()->count(3)->published()->create(['genre_id' => $genre->id, 'plays_count' => 20]);
        Track::factory()->published()->create(['genre_id' => $genre->id, 'plays_count' => 2]);
        $this->getJson('/api/v1/tracks?genre_id='.$genre->id.'&min_plays=10&per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonStructure(['data', 'links', 'meta']);
        $this->getJson('/api/v1/tracks?min_plays=-1')->assertStatus(422)->assertJsonValidationErrors(['min_plays']);
    }

    public function test_stats_are_scoped_to_current_artist(): void
    {
        $artist = User::factory()->artist()->create();
        Track::factory()->published()->create(['user_id' => $artist->id, 'plays_count' => 7]);
        Track::factory()->pending()->create(['user_id' => $artist->id, 'plays_count' => 0]);
        Track::factory()->published()->create(['plays_count' => 100]);
        Sanctum::actingAs($artist);
        $this->getJson('/api/v1/me/tracks/stats')->assertOk()->assertJsonPath('data.tracks', 2)->assertJsonPath('data.plays', 7)->assertJsonPath('data.by_status.published', 1);
        $this->getJson('/api/v1/me/tracks/stats?to=2100-01-01')->assertOk();
        $this->getJson('/api/v1/me/tracks/stats?from=2026-11-10&to=2026-11-01')->assertStatus(422);
    }
}
