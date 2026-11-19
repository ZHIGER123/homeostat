<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('tracks', function (Blueprint $table) { $table->boolean('is_spotlight')->default(false); $table->boolean('is_boosted')->default(false); $table->boolean('comments_enabled')->default(true); });
        Schema::create('artist_feature_usage', function (Blueprint $table) { $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete(); $table->string('feature', 30); $table->timestamp('created_at'); $table->index(['user_id', 'feature', 'created_at']); });
    }
    public function down(): void { Schema::dropIfExists('artist_feature_usage'); Schema::table('tracks', fn (Blueprint $table) => $table->dropColumn(['is_spotlight','is_boosted','comments_enabled'])); }
};
