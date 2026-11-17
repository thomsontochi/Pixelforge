<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;

class SyncStaleGenerations extends Command
{
    protected $signature = 'generations:sync';

    protected $description = 'Poll Pixelforge for running generations we have not heard about recently';

    public function handle(): int
    {
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subMinutes(2))
            ->pluck('id');

        $stale->each(fn (int $id) => SyncGeneration::dispatch($id));

        // A submit job can be lost (e.g. the jobs table cleared on deploy). Re-send those:
        // the Idempotency-Key means Pixelforge never creates a second generation.
        $unsent = Generation::query()
            ->where('status', GenerationStatus::Pending->value)
            ->where('updated_at', '<', now()->subMinutes(5))
            ->pluck('id');

        $unsent->each(fn (int $id) => SubmitGeneration::dispatch($id));

        $this->info("Checking {$stale->count()} running and re-sending {$unsent->count()} unsent generation(s).");

        return self::SUCCESS;
    }
}
