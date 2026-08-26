<?php

namespace App\Models;

use Database\Factories\PartnerBalanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerBalance extends Model
{
    /** @use HasFactory<PartnerBalanceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'partner_id',
        'currency',
        'available',
        'pending',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'integer',
            'pending' => 'integer',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
