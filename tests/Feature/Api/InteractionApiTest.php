<?php

namespace Tests\Feature\Api;

use App\Models\Comment;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InteractionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_like_is_idempotent_and_unlike_returns_204(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->published()->create();
        $url = "/api/v1/users/{$user->id}/likes/{$track->id}";

        $this->putJson($url)->assertCreated()->assertJson(['liked' => true, 'likes_count' => 1]);
        $this->putJson($url)->assertOk()->assertJson(['liked' => true, 'likes_count' => 1]);
        $this->assertDatabaseCount('likes', 1);

        $this->getJson("/api/v1/users/{$user->id}/likes")->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson($url)->assertNoContent();
        $this->assertDatabaseCount('likes', 0);
    }

    public function test_like_errors(): void
    {
        $user = User::factory()->create();
        $blocked = User::factory()->blocked()->create();
        $track = Track::factory()->published()->create();
        $pending = Track::factory()->pending()->create();

        $this->putJson("/api/v1/users/{$blocked->id}/likes/{$track->id}")->assertForbidden();
        $this->putJson("/api/v1/users/{$user->id}/likes/{$pending->id}")->assertNotFound();
        $this->putJson("/api/v1/users/{$user->id}/likes/999999")->assertNotFound();
        $this->putJson("/api/v1/users/999999/likes/{$track->id}")->assertNotFound();
    }

    public function test_comments_can_be_created_listed_and_deleted(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->published()->create();

        $this->postJson("/api/v1/tracks/{$track->id}/comments", ['user_id' => $user->id, 'body' => 'Отличный драйв!'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Отличный драйв!')
            ->assertJsonPath('data.author.id', $user->id);

        $this->getJson("/api/v1/tracks/{$track->id}/comments")->assertOk()->assertJsonCount(1, 'data');

        $comment = Comment::first();
        $this->deleteJson('/api/v1/comments/'.$comment->id)->assertNoContent();
        $this->deleteJson('/api/v1/comments/'.$comment->id)->assertNotFound();
    }

    public function test_comment_validation_and_business_errors(): void
    {
        $user = User::factory()->create();
        $blocked = User::factory()->blocked()->create();
        $track = Track::factory()->published()->create();
        $pending = Track::factory()->pending()->create();

        $this->postJson("/api/v1/tracks/{$track->id}/comments", ['user_id' => $user->id, 'body' => 'a'])
            ->assertStatus(422)->assertJsonValidationErrors(['body']);
        $this->postJson("/api/v1/tracks/{$track->id}/comments", ['body' => 'Привет'])
            ->assertStatus(422)->assertJsonValidationErrors(['user_id']);
        $this->postJson("/api/v1/tracks/{$track->id}/comments", ['user_id' => $blocked->id, 'body' => 'Привет'])
            ->assertForbidden();
        $this->postJson("/api/v1/tracks/{$pending->id}/comments", ['user_id' => $user->id, 'body' => 'Привет'])
            ->assertStatus(409);
        $this->postJson('/api/v1/tracks/999999/comments', ['user_id' => $user->id, 'body' => 'Привет'])
            ->assertNotFound();
    }

    public function test_follow_unfollow_and_feed(): void
    {
        $listener = User::factory()->create();
        $artist = User::factory()->artist()->create();
        $other = User::factory()->artist()->create();
        Track::factory()->published()->create(['user_id' => $artist->id, 'title' => 'From followed']);
        Track::factory()->published()->create(['user_id' => $other->id]);
        Track::factory()->pending()->create(['user_id' => $artist->id]);

        $url = "/api/v1/users/{$listener->id}/following/{$artist->id}";
        $this->putJson($url)->assertCreated()->assertJson(['following' => true]);
        $this->putJson($url)->assertOk();

        $this->getJson("/api/v1/users/{$listener->id}/feed")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'From followed');
        $this->getJson("/api/v1/users/{$artist->id}")->assertOk()->assertJsonPath('data.followers_count', 1);

        $this->deleteJson($url)->assertNoContent();
        $this->getJson("/api/v1/users/{$listener->id}/feed")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_follow_rules(): void
    {
        $listener = User::factory()->create();
        $other = User::factory()->create();
        $artist = User::factory()->artist()->create();

        $this->putJson("/api/v1/users/{$listener->id}/following/{$other->id}")->assertStatus(422);
        $this->putJson("/api/v1/users/{$artist->id}/following/{$artist->id}")->assertStatus(422);
        $this->putJson("/api/v1/users/{$listener->id}/following/999999")->assertNotFound();
    }

    public function test_admin_can_block_and_unblock_user_but_not_another_admin(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->putJson("/api/v1/admin/users/{$user->id}/block")->assertOk()->assertJsonPath('data.is_blocked', true);
        $this->assertTrue($user->fresh()->is_blocked);

        $this->deleteJson("/api/v1/admin/users/{$user->id}/block")->assertNoContent();
        $this->assertFalse($user->fresh()->is_blocked);

        $this->putJson("/api/v1/admin/users/{$admin->id}/block")->assertStatus(409);
        $this->putJson('/api/v1/admin/users/999999/block')->assertNotFound();
    }

    public function test_user_profile_hides_email_and_password(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/v1/users/'.$user->id)
            ->assertOk()
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.password');
    }
}
