<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider')->default('stripe');
            $table->string('provider_customer_id')->nullable()->index();
            $table->string('provider_subscription_id')->nullable()->unique();
            $table->string('plan')->default('free');
            $table->string('status')->default('active');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('subscriptions'); }
};
