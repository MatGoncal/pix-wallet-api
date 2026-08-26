<?php

namespace App\Services;

use App\Enums\PaymentStatusEnum;
use App\Models\Partner;
use App\Models\Payment;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly FakePixProvider $pixProvider,
    ) {}

    /**
     * @param  array{amount: int, currency: string, external_id?: string|null, description?: string|null, expires_in_seconds?: int|null}  $data
     */
    public function create(Partner $partner, array $data): Payment
    {
        $paymentId = (string) Str::uuid();
        $expiresIn = $data['expires_in_seconds'] ?? 1800;
        $charge = $this->pixProvider->createCharge(
            $data['amount'],
            $data['currency'],
            $paymentId,
        );

        return Payment::query()->create([
            'id' => $paymentId,
            'partner_id' => $partner->id,
            'status' => PaymentStatusEnum::Pending,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'external_id' => $data['external_id'] ?? null,
            'description' => $data['description'] ?? null,
            'qr_code' => $charge['qr_code'],
            'copy_paste' => $charge['copy_paste'],
            'provider' => $charge['provider'],
            'expires_at' => now()->addSeconds($expiresIn),
        ]);
    }

    public function findForPartner(Partner $partner, string $paymentId): ?Payment
    {
        return Payment::query()
            ->where('partner_id', $partner->id)
            ->where('id', $paymentId)
            ->first();
    }
}
