<?php

namespace App\Services;

/**
 * Synthetic FX rates — never float arithmetic for money conversion.
 * Rate stored/returned as exact decimal string; conversion via bcmath.
 */
class FakeFxProvider
{
    /**
     * Rate keyed as SOURCE_TARGET (how many target units per 1 source unit).
     *
     * @var array<string, string>
     */
    private const RATES = [
        'BRL_USD' => '0.18500000',
        'BRL_EUR' => '0.17100000',
        'USD_BRL' => '5.42000000',
        'EUR_BRL' => '5.85000000',
        'USD_EUR' => '0.92000000',
        'EUR_USD' => '1.08700000',
    ];

    public function rate(string $sourceCurrency, string $targetCurrency): string
    {
        $source = strtoupper($sourceCurrency);
        $target = strtoupper($targetCurrency);

        if ($source === $target) {
            return '1.00000000';
        }

        $key = $source.'_'.$target;

        return self::RATES[$key] ?? '1.00000000';
    }

    /**
     * Convert source minor units → target minor units using half-up.
     */
    public function convert(int $sourceAmountMinor, string $sourceCurrency, string $targetCurrency): array
    {
        $rate = $this->rate($sourceCurrency, $targetCurrency);
        $product = bcmul((string) $sourceAmountMinor, $rate, 8);
        $targetAmount = (int) $this->bcRoundHalfUp($product, 0);

        return [
            'rate' => $rate,
            'target_amount' => $targetAmount,
        ];
    }

    private function bcRoundHalfUp(string $value, int $scale): string
    {
        if (! str_contains($value, '.')) {
            return bcadd($value, '0', $scale);
        }

        [$int, $frac] = explode('.', $value, 2);
        $pad = str_pad($frac, $scale + 1, '0');
        $keep = substr($pad, 0, $scale);
        $next = (int) substr($pad, $scale, 1);

        $base = $scale === 0 ? $int : $int.'.'.$keep;

        if ($next >= 5) {
            $increment = $scale === 0 ? '1' : '0.'.str_repeat('0', $scale - 1).'1';

            return bcadd($base, $increment, $scale);
        }

        return bcadd($base, '0', $scale);
    }
}
