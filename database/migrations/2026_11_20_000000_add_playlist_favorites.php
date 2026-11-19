<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) { $table->boolean('is_public')->default(false); });
        Schema::create('playlist_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'playlist_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('playlist_likes');
        Schema::table('playlists', function (Blueprint $table) { $table->dropColumn('is_public'); });
    }
};
