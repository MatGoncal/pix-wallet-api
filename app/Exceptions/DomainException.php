<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class DomainException extends Exception
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly int $errorCode,
        public readonly string $errorName,
        string $message,
        public readonly array $details = [],
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'name' => $this->errorName,
                'message' => $this->getMessage(),
                'details' => (object) $this->details,
            ],
        ], $this->httpStatus);
    }
}
