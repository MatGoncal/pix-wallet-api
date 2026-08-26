<?php

namespace App\Models;

use App\Enums\LedgerDirectionEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceLedgerEntry extends Model
{
    use HasUuids;

    protected $table = 'balance_ledger';

    protected $fillable = [
        'partner_id',
        'currency',
        'direction',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'direction' => LedgerDirectionEnum::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
