<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 */
class FxQuote extends Model
{
    use HasUuids;

    protected $fillable = [
        'partner_id',
        'source_currency',
        'target_currency',
        'source_amount',
        'target_amount',
        'rate',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_amount' => 'integer',
            'target_amount' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
