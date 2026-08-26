<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentWebhookRequest;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function payment(PaymentWebhookRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $event = DB::transaction(function () use ($data) {
                $event = WebhookEvent::query()->create([
                    'provider' => $data['provider'],
                    'event_id' => $data['event_id'],
                    'type' => $data['type'],
                    'payload' => $data,
                    'payment_id' => $data['payment_id'],
                ]);

                // Without afterCommit the worker can pick the job up before the
                // event row is visible, and a rollback would leave a job that
                // points at an event that never existed.
                ProcessPaymentWebhook::dispatch($event->id)->afterCommit();

                return $event;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return response()->json([
                    'accepted' => true,
                    'duplicate' => true,
                    'error' => [
                        'code' => 1042,
                        'name' => 'duplicate_event',
                        'message' => 'Event already processed.',
                        'details' => [
                            'event_id' => $data['event_id'],
                        ],
                    ],
                ]);
            }

            throw $e;
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => false,
            'event_id' => $event->id,
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        // PostgreSQL unique_violation
        if ($sqlState === '23505') {
            return true;
        }

        // SQLite (tests fallback)
        return str_contains(strtolower($e->getMessage()), 'unique');
    }
}
