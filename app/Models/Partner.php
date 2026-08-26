<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'api_key_hash',
        'api_key_prefix',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(PartnerBalance::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public static function hashApiKey(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }
}
