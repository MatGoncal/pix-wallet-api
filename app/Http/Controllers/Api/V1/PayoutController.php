<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePayoutRequest;
use App\Models\Partner;
use App\Models\Payout;
use App\Services\IdempotencyService;
use App\Services\PayoutService;
use Illuminate\Http\JsonResponse;

class PayoutController extends Controller
{
    public function __construct(
        private readonly PayoutService $payouts,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function store(StorePayoutRequest $request): JsonResponse
    {
        /** @var Partner $partner */
        $partner = $request->attributes->get('partner');

        return $this->idempotency->run($request, $partner, function (?string $resourceId) use ($request, $partner) {
            $payout = $this->payouts->create($partner, $request->validated());

            return response()->json($this->transform($payout), 202);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Payout $payout): array
    {
        return [
            'id' => $payout->id,
            'status' => $payout->status->value,
            'amount' => $payout->amount,
            'currency' => $payout->currency,
            'external_id' => $payout->external_id,
            'created_at' => $payout->created_at?->toIso8601String(),
        ];
    }
}
