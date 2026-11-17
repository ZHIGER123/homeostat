<?php

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenreApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_genres_with_published_tracks_count(): void
    {
        $genre = Genre::factory()->create(['name' => 'Ambient', 'slug' => 'ambient']);
        Track::factory()->count(2)->published()->create(['genre_id' => $genre->id]);
        Track::factory()->pending()->create(['genre_id' => $genre->id]);

        $this->getJson('/api/v1/genres')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'ambient')
            ->assertJsonPath('data.0.tracks_count', 2);
    }

    public function test_store_creates_genre_and_generates_slug_from_name(): void
    {
        $this->postJson('/api/v1/genres', ['name' => 'Lo-fi Beats'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'lo-fi-beats');

        $this->assertDatabaseHas('genres', ['name' => 'Lo-fi Beats']);
    }

    public function test_store_rejects_duplicates_and_bad_slug(): void
    {
        Genre::factory()->create(['name' => 'Jazz', 'slug' => 'jazz']);

        $this->postJson('/api/v1/genres', ['name' => 'Jazz', 'slug' => 'jazz'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'slug']);
        $this->postJson('/api/v1/genres', ['name' => 'Rock', 'slug' => 'bad slug!'])
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);
        $this->postJson('/api/v1/genres', [])->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_show_update_and_404(): void
    {
        $genre = Genre::factory()->create(['name' => 'Rock', 'slug' => 'rock']);

        $this->getJson('/api/v1/genres/'.$genre->id)->assertOk()->assertJsonPath('data.name', 'Rock');
        $this->putJson('/api/v1/genres/'.$genre->id, ['name' => 'Hard Rock'])
            ->assertOk()->assertJsonPath('data.name', 'Hard Rock');
        $this->getJson('/api/v1/genres/999999')->assertNotFound();
        $this->putJson('/api/v1/genres/999999', ['name' => 'x'])->assertNotFound();
    }

    public function test_update_allows_keeping_own_unique_values(): void
    {
        $genre = Genre::factory()->create(['name' => 'Rock', 'slug' => 'rock']);

        $this->patchJson('/api/v1/genres/'.$genre->id, ['name' => 'Rock', 'slug' => 'rock'])->assertOk();
    }

    public function test_destroy_returns_204_for_empty_genre_and_409_when_tracks_exist(): void
    {
        $empty = Genre::factory()->create();
        $used = Genre::factory()->create();
        Track::factory()->create(['genre_id' => $used->id]);

        $this->deleteJson('/api/v1/genres/'.$empty->id)->assertNoContent();
        $this->assertDatabaseMissing('genres', ['id' => $empty->id]);

        $this->deleteJson('/api/v1/genres/'.$used->id)->assertStatus(409);
        $this->assertDatabaseHas('genres', ['id' => $used->id]);
    }
}
