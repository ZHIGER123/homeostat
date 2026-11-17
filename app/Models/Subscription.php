<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_customer_id', 'provider_subscription_id', 'plan', 'status', 'current_period_start', 'current_period_end', 'cancel_at_period_end'];
    protected function casts(): array { return ['current_period_start' => 'datetime', 'current_period_end' => 'datetime', 'cancel_at_period_end' => 'boolean']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function active(): bool { return in_array($this->status, ['active', 'trialing'], true) && (! $this->current_period_end || $this->current_period_end->isFuture()); }
}
