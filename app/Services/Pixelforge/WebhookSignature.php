<?php

namespace App\Services\Pixelforge;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix time>,v1=<hex HMAC-SHA256 of "t.body">".
 */
class WebhookSignature
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function isValid(?string $header, string $rawBody, ?int $now = null): bool
    {
        if ($header === null || $header === '' || $this->secret === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = $parts['v1'] ?? '';

        // Reject old (or far-future) timestamps so a captured request can't be replayed later.
        if ($timestamp === 0 || abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);

        // Constant-time comparison, so the signature can't be guessed byte by byte from response timing.
        return hash_equals($expected, $signature);
    }

    public static function sign(string $secret, string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }
}
