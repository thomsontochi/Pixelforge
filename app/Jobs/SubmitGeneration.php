<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use DateTimeInterface;
use Throwable;

/**
 * Sends one generation to Pixelforge, retrying temporary failures.
 *
 * Safe to retry: we always send the generation's uuid as the Idempotency-Key, so if a
 * request reached Pixelforge but the response was lost, the retry returns the same
 * generation instead of creating (and billing) a second one.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    // Give up on temporary errors (rate limits, overloads) after this many tries.
    public const MAX_ATTEMPTS = 10;

    public int $tries = 0; // unlimited releases; we stop ourselves via MAX_ATTEMPTS and retryUntil()

    // If something unexpected throws (not a Pixelforge API error), stop after 3 and don't hot-loop.
    public int $maxExceptions = 3;

    /** @var array<int, int> seconds to wait after an unexpected exception */
    public array $backoff = [5, 15, 30];

    public function __construct(public readonly int $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return; // Already accepted (e.g. a webhook beat us here) or already final.
        }

        $generation->increment('submit_attempts');

        try {
            $remote = $pixelforge->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => [
                    'generation_uuid' => $generation->uuid,
                    'user_id' => (string) $generation->user_id,
                ],
            ], $generation->uuid);
        } catch (PixelforgeException $e) {
            $this->handleError($e, $generation, $updater);

            return;
        }

        $generation->forceFill(['submitted_at' => now()])->save();
        $updater->apply($generation, $remote);
    }

    private function handleError(PixelforgeException $e, Generation $generation, GenerationUpdater $updater): void
    {
        if (! $e->isRetryable()) {
            // Rejected outright (content policy, validation, daily quota, bad key). Nothing was created or billed.
            $updater->reject($generation, $e->errorCode ?? 'rejected', $e->getMessage());

            return;
        }

        if ($generation->submit_attempts >= self::MAX_ATTEMPTS) {
            $updater->reject($generation, 'gave_up', 'Pixelforge stayed busy, so we stopped retrying. You were not charged.');

            return;
        }

        $wait = $this->backoffSeconds($e, $generation->submit_attempts);

        $generation->forceFill([
            'notice' => match ($e->errorCode) {
                'concurrency_limit_exceeded' => 'Waiting for one of your running generations to finish.',
                'rate_limit_exceeded' => 'Pixelforge rate limit reached, retrying shortly.',
                default => 'Pixelforge is busy, retrying shortly.',
            },
        ])->save();

        $this->release($wait);
    }

    /**
     * Respect Retry-After, and back off further on each attempt (capped at 60s).
     */
    private function backoffSeconds(PixelforgeException $e, int $attempt): int
    {
        $retryAfter = $e->retryAfter ?? 2;

        return min(60, $retryAfter + (2 ** min($attempt, 5)) - 1);
    }

    public function failed(?Throwable $exception): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation !== null && ! $generation->status->isFinal()) {
            app(GenerationUpdater::class)->reject($generation, 'submit_failed', 'We could not send this to Pixelforge. You were not charged.');
        }
    }
}
