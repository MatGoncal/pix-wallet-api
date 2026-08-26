<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\FxQuote;
use App\Models\Partner;
use Illuminate\Support\Str;

class FxService
{
    public function __construct(
        private readonly FakeFxProvider $fxProvider,
    ) {}

    /**
     * @param  array{source_currency: string, target_currency: string, amount: int}  $data
     */
    public function createQuote(Partner $partner, array $data): FxQuote
    {
        $source = strtoupper($data['source_currency']);
        $target = strtoupper($data['target_currency']);
        $sourceAmount = $data['amount'];

        $converted = $this->fxProvider->convert($sourceAmount, $source, $target);
        $lockSeconds = (int) config('acmepay.fx_rate_lock_seconds', 300);

        return FxQuote::query()->create([
            'id' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'source_currency' => $source,
            'target_currency' => $target,
            'source_amount' => $sourceAmount,
            'target_amount' => $converted['target_amount'],
            'rate' => $converted['rate'],
            'expires_at' => now()->addSeconds($lockSeconds),
        ]);
    }

    /**
     * @throws DomainException
     */
    public function assertUsable(FxQuote $quote): void
    {
        if ($quote->isExpired()) {
            throw new DomainException(
                1031,
                'quote_expired',
                'FX quote past expires_at (rate lock window).',
                [
                    'quote_id' => $quote->id,
                    'expires_at' => $quote->expires_at?->toIso8601String(),
                ],
            );
        }
    }
}
