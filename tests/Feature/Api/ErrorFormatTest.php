<?php

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_errors_are_json_and_in_russian(): void
    {
        $this->getJson('/api/v1/tracks?per_page=abc')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ошибка валидации.')
            ->assertJsonPath('errors.per_page.0', 'Поле per page должно быть целым числом.');
    }

    public function test_missing_and_referenced_records_have_consistent_messages(): void
    {
        $this->getJson('/api/v1/genres/999999')->assertNotFound()
            ->assertExactJson(['message' => 'Ресурс не найден.']);

        $genre = Genre::factory()->create();
        Track::factory()->create(['genre_id' => $genre->id]);
        $this->deleteJson('/api/v1/genres/'.$genre->id)->assertStatus(409)
            ->assertJsonStructure(['message']);
    }

    public function test_server_error_does_not_leak_details_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/test-unexpected-error', fn () => throw new \RuntimeException('SECRET SQL SELECT * FROM users'));

        $this->getJson('/api/test-unexpected-error')->assertStatus(500)
            ->assertJsonStructure(['message'])
            ->assertDontSee('SECRET SQL')
            ->assertDontSee('RuntimeException');
    }
}
