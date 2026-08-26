<?php

namespace App\Models;

use App\Enums\PayoutStatusEnum;
use Database\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property PayoutStatusEnum $status
 * @property Carbon|null $created_at
 */
class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'partner_id',
        'status',
        'amount',
        'currency',
        'destination_type',
        'destination_value',
        'external_id',
        'failure_code',
        'failure_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatusEnum::class,
            'amount' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
