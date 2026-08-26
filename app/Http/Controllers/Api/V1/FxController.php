<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFxQuoteRequest;
use App\Models\FxQuote;
use App\Models\Partner;
use App\Services\FxService;
use Illuminate\Http\JsonResponse;

class FxController extends Controller
{
    public function __construct(
        private readonly FxService $fx,
    ) {}

    public function store(StoreFxQuoteRequest $request): JsonResponse
    {
        /** @var Partner $partner */
        $partner = $request->attributes->get('partner');

        $quote = $this->fx->createQuote($partner, $request->validated());

        return response()->json($this->transform($quote), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(FxQuote $quote): array
    {
        return [
            'quote_id' => $quote->id,
            'source_currency' => $quote->source_currency,
            'target_currency' => $quote->target_currency,
            'source_amount' => $quote->source_amount,
            'target_amount' => $quote->target_amount,
            'rate' => $quote->rate,
            'expires_at' => $quote->expires_at?->toIso8601String(),
            'created_at' => $quote->created_at?->toIso8601String(),
        ];
    }
}
