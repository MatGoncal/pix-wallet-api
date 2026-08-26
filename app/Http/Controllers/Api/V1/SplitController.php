<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentSplitsRequest;
use App\Models\Partner;
use App\Services\SplitService;
use Illuminate\Http\JsonResponse;

class SplitController extends Controller
{
    public function __construct(
        private readonly SplitService $splits,
    ) {}

    public function store(StorePaymentSplitsRequest $request, string $id): JsonResponse
    {
        /** @var Partner $partner */
        $partner = $request->attributes->get('partner');

        try {
            $lines = $this->splits->define($partner, $id, $request->validated('splits'));
        } catch (DomainException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'payment_id' => $id,
            'splits' => $lines->map(fn ($line) => [
                'party' => $line->party,
                'amount' => $line->amount,
            ])->values()->all(),
        ], 201);
    }
}
