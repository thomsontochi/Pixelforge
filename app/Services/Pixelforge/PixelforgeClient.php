<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Pixelforge HTTP API. It only talks HTTP; decisions about
 * retries and billing live in the job and the GenerationUpdater.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    /**
     * Current pricing. Public and cheap, cached for 5 minutes. Rates that apply to a
     * generation are the ones returned on the generation itself, so caching only affects estimates.
     *
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', now()->addMinutes(5), function () {
            return $this->send(fn () => Http::baseUrl($this->baseUrl)->acceptJson()->timeout(10)->get('/pricing'));
        });
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $parameters, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $parameters));
    }

    /**
     * @return array<string, mixed> the generation object
     */
    public function getGeneration(string $id): array
    {
        return $this->send(fn () => $this->request()->get("/generations/{$id}"));
    }

    /**
     * Billed usage per UTC day for this API key: the source of truth for provider costs.
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->request()->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    /**
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new PixelforgeException('Could not reach Pixelforge: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        $body = $response->json();

        if (! is_array($body)) {
            // Treat a 2xx with an empty or non-JSON body like an outage: retryable, never a crash.
            throw new PixelforgeException('Pixelforge returned an unreadable response.', 502);
        }

        return $body;
    }
}
