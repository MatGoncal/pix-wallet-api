<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileBalancesCommand extends Command
{
    protected $signature = 'acmepay:reconcile';

    protected $description = 'Compare available+pending against the ledger net (read-only)';

    public function handle(): int
    {
        $mismatches = DB::select($this->diffSql());

        foreach ($mismatches as $row) {
            $payload = [
                'partner_id' => $row->partner_id,
                'currency' => $row->currency,
                'available' => (int) $row->available,
                'pending' => (int) $row->pending,
                'wallet_sum' => (int) $row->wallet_sum,
                'ledger_sum' => (int) $row->ledger_sum,
                'delta' => (int) $row->delta,
            ];

            Log::warning('ledger_mismatch', $payload);
            $this->line(json_encode(
                ['event' => 'ledger_mismatch', ...$payload],
                JSON_THROW_ON_ERROR,
            ));
        }

        return $mismatches === [] ? self::SUCCESS : self::FAILURE;
    }

    private function diffSql(): string
    {
        return <<<'SQL'
            WITH ledger AS (
                SELECT partner_id,
                       currency,
                       COALESCE(SUM(
                           CASE WHEN direction = 'credit' THEN amount ELSE -amount END
                       ), 0) AS ledger_sum
                FROM balance_ledger
                GROUP BY partner_id, currency
            ),
            wallets AS (
                SELECT partner_id,
                       currency,
                       available,
                       pending,
                       available + pending AS wallet_sum
                FROM partner_balances
            )
            SELECT COALESCE(w.partner_id, l.partner_id) AS partner_id,
                   COALESCE(w.currency, l.currency) AS currency,
                   COALESCE(w.available, 0) AS available,
                   COALESCE(w.pending, 0) AS pending,
                   COALESCE(w.wallet_sum, 0) AS wallet_sum,
                   COALESCE(l.ledger_sum, 0) AS ledger_sum,
                   COALESCE(w.wallet_sum, 0) - COALESCE(l.ledger_sum, 0) AS delta
            FROM wallets w
            FULL OUTER JOIN ledger l
                ON w.partner_id = l.partner_id AND w.currency = l.currency
            WHERE COALESCE(w.wallet_sum, 0) <> COALESCE(l.ledger_sum, 0)
            SQL;
    }
}
