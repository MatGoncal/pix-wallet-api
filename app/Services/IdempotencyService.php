<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\IdempotencyKey;
use App\Models\Partner;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class IdempotencyService
{
    private const WAIT_TIMEOUT_SECONDS = 10;

    private const WAIT_INTERVAL_MICROSECONDS = 50_000;

    /**
     * @param  Closure(string|null): JsonResponse  $execute
     */
    public function run(
        Request $request,
        Partner $partner,
        Closure $execute,
        bool $retainResource = false,
    ): JsonResponse {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || $key === '') {
            return $execute(null);
        }

        return $this->runKeyed(
            $partner,
            $key,
            $request->method(),
            $request->path(),
            $request->getContent(),
            $execute,
            $retainResource,
        );
    }

    /**
     * @param  Closure(string|null): JsonResponse  $execute
     */
    public function runKeyed(
        Partner $partner,
        string $key,
        string $method,
        string $path,
        string $rawBody,
        Closure $execute,
        bool $retainResource = false,
    ): JsonResponse {
        $requestHash = hash('sha256', $rawBody);
        $resourceId = $retainResource ? (string) Str::uuid() : null;

        try {
            $row = DB::transaction(function () use ($partner, $key, $method, $path, $requestHash, $resourceId) {
                return IdempotencyKey::query()->create([
                    'partner_id' => $partner->id,
                    'key' => $key,
                    'resource_id' => $resourceId,
                    'method' => $method,
                    'path' => $path,
                    'request_hash' => $requestHash,
                    'expires_at' => now()->addDay(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->onExistingKey($partner->id, $key, $requestHash, $execute, $retainResource);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                return $this->onExistingKey($partner->id, $key, $requestHash, $execute, $retainResource);
            }

            throw $e;
        }

        return $this->executeAndPersist($row, $execute, $retainResource);
    }

    /**
     * @param  Closure(string|null): JsonResponse  $execute
     */
    private function executeAndPersist(
        IdempotencyKey $row,
        Closure $execute,
        bool $retainResource,
    ): JsonResponse {
        try {
            $response = $execute($row->resource_id);
        } catch (Throwable $e) {
            if (! $retainResource) {
                $row->delete();
            }

            throw $e;
        }

        $row->update([
            'response_code' => $response->status(),
            'response_body' => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        ]);

        return $response;
    }

    /**
     * @param  Closure(string|null): JsonResponse  $execute
     */
    private function onExistingKey(
        string $partnerId,
        string $key,
        string $requestHash,
        Closure $execute,
        bool $retainResource,
    ): JsonResponse {
        $existing = IdempotencyKey::query()
            ->where('partner_id', $partnerId)
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                throw new DomainException(
                    1043,
                    'idempotency_conflict',
                    'Idempotency-Key was reused with a different request body.',
                    ['key' => $key],
                    409,
                );
            }

            if ($existing->response_code !== null && $existing->response_body !== null) {
                return response()->json($existing->response_body, $existing->response_code);
            }

            if ($retainResource && is_string($existing->resource_id) && $existing->resource_id !== '') {
                return $this->executeAndPersist($existing, $execute, $retainResource);
            }
        }

        return $this->waitForSnapshot($partnerId, $key, $requestHash);
    }

    private function waitForSnapshot(string $partnerId, string $key, string $requestHash): JsonResponse
    {
        $deadline = microtime(true) + self::WAIT_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            $replay = DB::transaction(function () use ($partnerId, $key, $requestHash) {
                $existing = IdempotencyKey::query()
                    ->where('partner_id', $partnerId)
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    return null;
                }

                if ($existing->request_hash !== $requestHash) {
                    throw new DomainException(
                        1043,
                        'idempotency_conflict',
                        'Idempotency-Key was reused with a different request body.',
                        ['key' => $key],
                        409,
                    );
                }

                if ($existing->response_code === null || $existing->response_body === null) {
                    return false;
                }

                return response()->json($existing->response_body, $existing->response_code);
            });

            if ($replay instanceof JsonResponse) {
                return $replay;
            }

            usleep(self::WAIT_INTERVAL_MICROSECONDS);
        }

        throw new DomainException(
            1043,
            'idempotency_conflict',
            'Idempotency-Key request is still in progress.',
            ['key' => $key],
            409,
        );
    }
}
