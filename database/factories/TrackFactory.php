<?php

namespace Database\Factories;

use App\Enums\TrackStatus;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Track> */
class TrackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->artist(),
            'genre_id' => Genre::factory(),
            'title' => fake()->sentence(3),
            'description' => fake('ru_RU')->optional(0.7)->realText(200),
            'audio_path' => 'tracks/'.fake()->uuid().'.mp3',
            'cover_path' => fake()->optional(0.7)->passthrough('covers/'.fake()->uuid().'.jpg'),
            'duration' => fake()->numberBetween(90, 420),
            'plays_count' => 0,
            'status' => TrackStatus::Published,
            'reject_reason' => null,
        ];
    }

    public function pending(): static { return $this->state(fn () => ['status' => TrackStatus::Pending, 'plays_count' => 0]); }
    public function published(): static { return $this->state(fn () => ['status' => TrackStatus::Published]); }
    public function rejected(): static { return $this->state(fn () => ['status' => TrackStatus::Rejected, 'plays_count' => 0, 'reject_reason' => 'Трек ожидает проверки.']); }
}
