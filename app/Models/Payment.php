<?php

namespace App\Models;

use App\Enums\PaymentStatusEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property PaymentStatusEnum $status
 * @property int $amount
 * @property Carbon|null $expires_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 */
class Payment extends Model
{
    use HasUuids;

    protected $fillable = [
        'partner_id',
        'status',
        'amount',
        'currency',
        'external_id',
        'description',
        'qr_code',
        'copy_paste',
        'provider',
        'provider_tx_id',
        'expires_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatusEnum::class,
            'amount' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(PaymentSplit::class);
    }
}
