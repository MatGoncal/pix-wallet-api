<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Partner;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function store(StorePaymentRequest $request): JsonResponse
    {
        /** @var Partner $partner */
        $partner = $request->attributes->get('partner');

        $payment = $this->payments->create($partner, $request->validated());

        return response()->json($this->transform($payment), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        /** @var Partner $partner */
        $partner = $request->attributes->get('partner');

        $payment = $this->payments->findForPartner($partner, $id);

        if ($payment === null) {
            return response()->json([
                'error' => [
                    'code' => 404,
                    'name' => 'not_found',
                    'message' => 'Payment not found.',
                    'details' => (object) [],
                ],
            ], 404);
        }

        return response()->json($this->transform($payment));
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'external_id' => $payment->external_id,
            'qr_code' => $payment->qr_code,
            'copy_paste' => $payment->copy_paste,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }
}
