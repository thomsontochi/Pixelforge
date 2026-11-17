<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A Pixelforge API error, keeping the fields we branch on: the error code and Retry-After.
 */
class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        return new self(
            $error['message'] ?? "Pixelforge returned HTTP {$response->status()}.",
            $response->status(),
            $error['code'] ?? null,
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * Temporary problems worth retrying: rate limits, the 3-at-a-time concurrency limit,
     * overloads and our own connection failures (status 0). Nothing was created, so a retry is safe.
     */
    public function isRetryable(): bool
    {
        return $this->status === 0
            || $this->status >= 500
            || in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded'], true);
    }
}
