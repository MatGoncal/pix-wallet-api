<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BalanceController extends Controller
{
    public function __construct(
        private readonly BalanceService $balances,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Partner $partner */
        $partner = $request->attributes->get('partner');

        return response()->json([
            'balances' => $this->balances->listForPartner($partner),
        ]);
    }
}
