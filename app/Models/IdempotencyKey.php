<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $partner_id
 * @property string $key
 * @property string $request_hash
 * @property int|null $response_code
 * @property array<string, mixed>|null $response_body
 * @property Carbon $expires_at
 */
class IdempotencyKey extends Model
{
    use HasUuids;

    protected $fillable = [
        'partner_id',
        'key',
        'method',
        'path',
        'request_hash',
        'response_code',
        'response_body',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
