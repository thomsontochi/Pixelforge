<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Polling fallback: webhook delivery isn't guaranteed, so ask Pixelforge directly
 * for generations that have been running a while without news.
 */
class SyncGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $generationId) {}

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null || $generation->pixelforge_id === null || $generation->status->isFinal()) {
            return;
        }

        try {
            $updater->apply($generation, $pixelforge->getGeneration($generation->pixelforge_id));
        } catch (PixelforgeException $e) {
            if ($e->isRetryable()) {
                $this->release($e->retryAfter ?? 10);

                return;
            }

            if ($e->status === 404) {
                // Pixelforge has no such generation, so it can't finish or bill. Release the hold.
                $updater->reject($generation, 'not_found', 'Pixelforge has no record of this generation. You were not charged.');

                return;
            }

            throw $e;
        }
    }

    /**
     * The job is dead (e.g. a persistent auth error). If we leave the generation running the hold
     * is never released, so call it failed rather than keep the user's credits reserved forever.
     */
    public function failed(?Throwable $exception): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation !== null && ! $generation->status->isFinal()) {
            app(GenerationUpdater::class)->reject($generation, 'sync_failed', 'We could not confirm this generation with Pixelforge. You were not charged.');
        }
    }
}
