<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    protected $fillable = ['name', 'email', 'oauth_provider', 'oauth_id', 'password', 'role', 'bio', 'avatar_path', 'is_blocked'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array { return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'role' => UserRole::class, 'is_blocked' => 'boolean']; }
    public function tracks(): HasMany { return $this->hasMany(Track::class); }
    public function subscription(): HasOne { return $this->hasOne(Subscription::class); }
    public function currentPlan(): string { $subscription = $this->relationLoaded('subscription') ? $this->subscription : $this->subscription()->first(); return $subscription && $subscription->active() ? $subscription->plan : 'free'; }
    public function playlists(): HasMany { return $this->hasMany(Playlist::class); }
    public function comments(): HasMany { return $this->hasMany(Comment::class); }
    public function likes(): HasMany { return $this->hasMany(Like::class); }
    public function likedTracks(): BelongsToMany { return $this->belongsToMany(Track::class, 'likes')->withTimestamps(); }
    public function following(): BelongsToMany { return $this->belongsToMany(User::class, 'follows', 'follower_id', 'followed_id')->withTimestamps(); }
    public function followers(): BelongsToMany { return $this->belongsToMany(User::class, 'follows', 'followed_id', 'follower_id')->withTimestamps(); }
    public function isAdmin(): bool { return $this->role === UserRole::Admin; }
    public function isArtist(): bool { return $this->role === UserRole::Artist; }
}
