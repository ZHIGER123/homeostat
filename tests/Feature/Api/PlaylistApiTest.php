<?php

namespace Tests\Feature\Api;

use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlaylistApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_store_creates_playlist_with_ordered_tracks(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        [$a, $b] = Track::factory()->count(2)->published()->create();

        $this->postJson('/api/v1/playlists', [
            'user_id' => $user->id,
            'title' => 'Для учёбы',
            'track_ids' => [$b->id, $a->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Для учёбы')
            ->assertJsonPath('data.tracks.0.id', $b->id)
            ->assertJsonPath('data.tracks.0.position', 1)
            ->assertJsonPath('data.tracks.1.position', 2);
    }

    public function test_store_validates_input(): void
    {
        $pending = Track::factory()->pending()->create();

        $this->postJson('/api/v1/playlists', ['title' => '', 'track_ids' => [$pending->id, 9999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'track_ids.0', 'track_ids.1']);
    }

    public function test_index_filters_by_owner_and_show_returns_404_for_unknown(): void
    {
        $mine = Playlist::factory()->create();
        Playlist::factory()->count(2)->create();

        $this->getJson('/api/v1/playlists?user_id='.$mine->user_id)
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/playlists/999999')->assertNotFound();
        $this->getJson('/api/v1/playlists?user_id=999999')->assertStatus(422);
    }

    public function test_update_renames_and_destroy_deletes(): void
    {
        $playlist = Playlist::factory()->create(['title' => 'Old']);

        $this->patchJson('/api/v1/playlists/'.$playlist->id, ['title' => 'New'])
            ->assertOk()->assertJsonPath('data.title', 'New');
        $this->patchJson('/api/v1/playlists/'.$playlist->id, ['title' => str_repeat('a', 256)])
            ->assertStatus(422);

        $this->deleteJson('/api/v1/playlists/'.$playlist->id)->assertNoContent();
        $this->assertDatabaseMissing('playlists', ['id' => $playlist->id]);
    }

    public function test_add_track_appends_position_and_rejects_duplicates(): void
    {
        $playlist = Playlist::factory()->create();
        [$a, $b] = Track::factory()->count(2)->published()->create();

        $this->postJson("/api/v1/playlists/{$playlist->id}/tracks", ['track_id' => $a->id])
            ->assertCreated()->assertJsonPath('data.tracks.0.position', 1);
        $this->postJson("/api/v1/playlists/{$playlist->id}/tracks", ['track_id' => $b->id])
            ->assertCreated()->assertJsonPath('data.tracks.1.position', 2);

        $this->postJson("/api/v1/playlists/{$playlist->id}/tracks", ['track_id' => $a->id])->assertStatus(409);
    }

    public function test_add_track_rejects_unpublished_and_unknown_tracks(): void
    {
        $playlist = Playlist::factory()->create();
        $pending = Track::factory()->pending()->create();

        $this->postJson("/api/v1/playlists/{$playlist->id}/tracks", ['track_id' => $pending->id])
            ->assertStatus(422)->assertJsonValidationErrors(['track_id']);
        $this->postJson("/api/v1/playlists/{$playlist->id}/tracks", ['track_id' => 9999])->assertStatus(422);
        $this->postJson('/api/v1/playlists/999999/tracks', ['track_id' => 1])->assertNotFound();
    }

    public function test_remove_track_renumbers_positions_and_returns_404_if_not_in_playlist(): void
    {
        $playlist = Playlist::factory()->create();
        [$a, $b, $c] = Track::factory()->count(3)->published()->create();
        $playlist->tracks()->attach([$a->id => ['position' => 1], $b->id => ['position' => 2], $c->id => ['position' => 3]]);
        $stranger = Track::factory()->published()->create();

        $this->deleteJson("/api/v1/playlists/{$playlist->id}/tracks/{$a->id}")->assertNoContent();

        $this->assertSame([1, 2], $playlist->tracks()->get()->pluck('pivot.position')->all());
        $this->assertSame([$b->id, $c->id], $playlist->tracks()->pluck('tracks.id')->all());
        $this->deleteJson("/api/v1/playlists/{$playlist->id}/tracks/{$stranger->id}")->assertNotFound();
    }

    public function test_reorder_changes_order_and_validates_the_list(): void
    {
        $playlist = Playlist::factory()->create();
        [$a, $b, $c] = Track::factory()->count(3)->published()->create();
        $playlist->tracks()->attach([$a->id => ['position' => 1], $b->id => ['position' => 2], $c->id => ['position' => 3]]);

        $this->putJson("/api/v1/playlists/{$playlist->id}/tracks", ['track_ids' => [$c->id, $a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.tracks.0.id', $c->id)
            ->assertJsonPath('data.tracks.2.id', $b->id);

        $this->putJson("/api/v1/playlists/{$playlist->id}/tracks", ['track_ids' => [$a->id, $b->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['track_ids']);
        $this->putJson("/api/v1/playlists/{$playlist->id}/tracks", ['track_ids' => [$a->id, $a->id, $b->id]])
            ->assertStatus(422);
    }
}
