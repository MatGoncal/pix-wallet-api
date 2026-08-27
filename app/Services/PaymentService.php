<?php

namespace App\Services;

use App\Enums\PaymentStatusEnum;
use App\Models\Partner;
use App\Models\Payment;
use Illuminate\Support\Collection;
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

        $payment = new Payment;
        $payment->id = $paymentId;
        $payment->fill([
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
        $payment->save();

        return $payment;
    }

    public function findForPartner(Partner $partner, string $paymentId): ?Payment
    {
        return Payment::query()
            ->where('partner_id', $partner->id)
            ->where('id', $paymentId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{data: Collection<int, Payment>, meta: array{page: int, per_page: int, total: int, total_pages: int}}
     */
    public function listForPartner(Partner $partner, array $query): array
    {
        $status = isset($query['status']) ? strtoupper((string) $query['status']) : null;
        $externalId = isset($query['external_id']) ? (string) $query['external_id'] : null;
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($query['per_page'] ?? 10)));

        $builder = Payment::query()
            ->where('partner_id', $partner->id)
            ->orderByDesc('created_at');

        if ($status) {
            $builder->where('status', $status);
        }

        if ($externalId !== null && $externalId !== '') {
            $builder->where('external_id', 'like', '%'.$externalId.'%');
        }

        $paginator = $builder->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->getCollection(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage() ?: 1,
            ],
        ];
    }
}
