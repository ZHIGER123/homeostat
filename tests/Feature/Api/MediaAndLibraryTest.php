<?php

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TestAudio;
use Tests\TestCase;

class MediaAndLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_measures_audio_and_ignores_forged_duration(): void
    {
        Storage::fake('local'); Storage::fake('public');
        $user = User::factory()->artist()->create(); Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/tracks', ['title' => 'Test', 'genre_id' => Genre::factory()->create()->id, 'audio' => TestAudio::wav(), 'duration' => 999]);
        $response->assertCreated()->assertJsonPath('data.duration', 1)->assertJsonPath('data.status', 'pending');
        $this->patchJson('/api/v1/tracks/'.$response->json('data.id'), ['duration' => 999])->assertOk()->assertJsonPath('data.duration', 1);
    }
    public function test_upload_rejects_invalid_audio_and_enforces_quota(): void
    {
        Storage::fake('local'); Storage::fake('public');
        $user = User::factory()->artist()->create(); Sanctum::actingAs($user);
        $genre = Genre::factory()->create();
        $this->postJson('/api/v1/tracks', ['title' => 'Bad', 'genre_id' => $genre->id, 'audio' => UploadedFile::fake()->create('bad.mp3', 20, 'audio/mpeg')])->assertUnprocessable()->assertJsonValidationErrors('audio');
        Track::factory()->published()->create(['user_id' => $user->id, 'duration' => 10800, 'created_at' => now()->subMonths(3)]);
        $this->postJson('/api/v1/tracks', ['title' => 'Over limit', 'genre_id' => $genre->id, 'audio' => TestAudio::wav()])->assertStatus(409);
        $this->assertDatabaseMissing('tracks', ['title' => 'Over limit']);
    }
    public function test_cover_works_without_public_storage_link_but_pending_is_not_public(): void
    {
        Storage::fake('public'); Storage::disk('public')->put('covers/test.jpg', 'image');
        $published = Track::factory()->published()->create(['cover_path' => 'covers/test.jpg']);
        $pending = Track::factory()->pending()->create(['cover_path' => 'covers/test.jpg']);
        $this->get('/api/v1/tracks/'.$published->id.'/cover')->assertOk();
        $this->get('/api/v1/tracks/'.$pending->id.'/cover')->assertNotFound();
    }
    public function test_avatar_upload_and_public_read(): void
    {
        Storage::fake('public'); $user = User::factory()->create(); Sanctum::actingAs($user);
        $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('avatar.png')])->assertOk();
        $this->assertStringStartsWith('avatars/', $user->fresh()->avatar_path);
        $this->get('/api/v1/users/'.$user->id.'/avatar')->assertOk();
        $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->create('bad.txt', 1, 'text/plain')])->assertUnprocessable();
    }
    public function test_api_without_accept_header_still_returns_401_json(): void
    {
        $this->get('/api/v1/me')->assertUnauthorized()->assertJsonStructure(['message']);
    }
    public function test_non_owner_never_sees_pending_tracks_in_public_playlist(): void
    {
        $owner = User::factory()->create(); $other = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $owner->id, 'is_public' => true]);
        $published = Track::factory()->published()->create(); $pending = Track::factory()->pending()->create();
        $playlist->tracks()->attach($published->id, ['position' => 1]);
        $playlist->tracks()->attach($pending->id, ['position' => 2]);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/playlists/'.$playlist->id)->assertOk()->assertJsonCount(1, 'data.tracks')->assertJsonPath('data.tracks.0.id', $published->id);
        $this->getJson('/api/v1/playlists/discover')->assertOk()->assertJsonPath('data.0.tracks_count', 1);
    }
    public function test_playlist_likes_respect_private_visibility_and_owner_permissions(): void
    {
        $owner = User::factory()->create(); $other = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $owner->id, 'is_public' => false]);
        Sanctum::actingAs($other);
        $this->putJson('/api/v1/playlists/'.$playlist->id.'/like')->assertNotFound();
        $this->getJson('/api/v1/playlists/'.$playlist->id)->assertForbidden();
        $playlist->update(['is_public' => true]);
        $this->putJson('/api/v1/playlists/'.$playlist->id.'/like')->assertNoContent();
        $this->putJson('/api/v1/playlists/'.$playlist->id.'/like')->assertNoContent();
        $this->getJson('/api/v1/me/liked-playlists')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/playlists/'.$playlist->id, ['title' => 'Not owner'])->assertForbidden();
        $playlist->update(['is_public' => false]);
        $this->getJson('/api/v1/me/liked-playlists')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/v1/playlists/'.$playlist->id.'/like')->assertNoContent();
    }
    public function test_artist_status_changes_only_after_successful_upload(): void
    {
        Storage::fake('local'); Storage::fake('public');
        $user = User::factory()->listener()->create(); Sanctum::actingAs($user);
        $genre = Genre::factory()->create();
        $this->postJson('/api/v1/tracks', ['title' => 'Bad', 'genre_id' => $genre->id, 'audio' => UploadedFile::fake()->create('bad.mp3', 20, 'audio/mpeg')])->assertUnprocessable();
        $this->assertSame('listener', $user->fresh()->role->value);
        $this->postJson('/api/v1/tracks', ['title' => 'First', 'genre_id' => $genre->id, 'audio' => TestAudio::wav()])->assertCreated();
        $this->assertSame('artist', $user->fresh()->role->value);
    }
    public function test_profile_social_links_are_validated_and_public(): void
    {
        $user = User::factory()->listener()->create(); Sanctum::actingAs($user);
        $this->patchJson('/api/v1/me', ['bio' => 'Астана · рок', 'social_links' => ['youtube' => 'https://youtube.com/example', 'telegram' => 'https://t.me/example']])->assertOk();
        $this->getJson('/api/v1/users/'.$user->id)->assertOk()->assertJsonPath('data.social_links.youtube', 'https://youtube.com/example');
        $this->patchJson('/api/v1/me', ['social_links' => ['telegram' => 'javascript:alert(1)']])->assertUnprocessable();
    }
    public function test_follow_status_for_not_yet_followed_artist_is_not_404(): void
    {
        $listener = User::factory()->listener()->create(); $artist = User::factory()->artist()->create(); Sanctum::actingAs($listener);
        $this->getJson('/api/v1/users/'.$listener->id.'/following/'.$artist->id)->assertOk()->assertJsonPath('following', false);
    }

}
