<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Billing\CreditLedger;
use App\Services\Billing\PriceCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a generation's state changes, whether the news comes from a webhook,
 * from our own POST response, or from polling. All three call apply() with Pixelforge's
 * generation object, so they can't disagree.
 *
 * Rules:
 *  - Status only moves forward (pending -> queued -> processing -> succeeded|failed).
 *    A late or duplicated event that is "behind" is ignored.
 *  - Billing happens exactly once, when the generation first becomes final.
 */
class GenerationUpdater
{
    /** Our own give-up codes: we told the user it failed, but Pixelforge may still have made it. */
    private const LOCAL_REJECTIONS = ['gave_up', 'submit_failed', 'not_found'];

    public function __construct(
        private readonly PriceCalculator $prices,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * Find our generation for a Pixelforge generation object. A webhook can arrive before our
     * POST returns, so we also match on the uuid we put in the metadata.
     *
     * @param  array<string, mixed>  $remote
     */
    public function findFor(array $remote): ?Generation
    {
        return Generation::query()
            ->where('pixelforge_id', $remote['id'] ?? null)
            ->when(isset($remote['metadata']['generation_uuid']), fn ($q) => $q->orWhere('uuid', $remote['metadata']['generation_uuid']))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $remote  a Pixelforge generation object
     */
    public function apply(Generation $generation, array $remote): Generation
    {
        $incoming = GenerationStatus::tryFrom((string) ($remote['status'] ?? ''));

        if ($incoming === null) {
            // Unknown status: log and ignore rather than throw (a throw would make Pixelforge retry forever).
            Log::warning('Pixelforge sent an unknown generation status', ['generation' => $generation->id, 'status' => $remote['status'] ?? null]);

            return $generation;
        }

        if ($incoming === GenerationStatus::Succeeded && ! $this->hasBillingData($remote, $generation)) {
            // We can't price it without usage and rates. Leave it running; polling will fetch the full object.
            Log::warning('Pixelforge success without usage or rates; waiting for polling', ['generation' => $generation->id]);

            return $generation;
        }

        return DB::transaction(function () use ($generation, $remote, $incoming) {
            // Lock the row so two webhooks for the same generation are applied one after the other.
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            $generation->pixelforge_id ??= $remote['id'] ?? null;
            $generation->rates ??= $remote['rates'] ?? null;

            if ($this->isLateSuccessAfterWeGaveUp($generation, $incoming)) {
                // We stopped retrying and told the user it failed, but Pixelforge did make it, and
                // bills us for it. Record the provider cost so the admin report matches /v1/usage.
                // The user keeps their "not charged" promise, and gets the image as a bonus.
                $this->recordProviderUsage($generation, $remote);
                $generation->save();

                return $generation;
            }

            if ($generation->status->isFinal() || $incoming->rank() <= $generation->status->rank()) {
                // Already final, or this news is older than what we know: keep what we have.
                // touch() marks that we checked, so polling doesn't ask about it again every minute.
                $generation->save();
                $generation->touch();

                return $generation;
            }

            $generation->status = $incoming;
            $generation->notice = null;
            $generation->started_at ??= isset($remote['started_at']) ? Carbon::parse($remote['started_at']) : null;

            if ($incoming === GenerationStatus::Succeeded) {
                $this->recordProviderUsage($generation, $remote);
                $generation->charged_pico = $this->prices->customerPrice($generation->provider_cost_pico);
            } elseif ($incoming === GenerationStatus::Failed) {
                $this->markFailed($generation, $remote['error']['code'] ?? 'generation_failed', $remote['error']['message'] ?? 'The generation failed.', $remote['completed_at'] ?? null);
            }

            $generation->save();

            if ($generation->status->isFinal()) {
                $this->ledger->settle($generation);
            }

            return $generation;
        });
    }

    /**
     * For requests Pixelforge never accepted (content policy, validation, daily limit, gave up retrying).
     * Nothing was created on their side, so nothing is billed.
     */
    public function reject(Generation $generation, string $code, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->status->isFinal()) {
                return $generation;
            }

            $generation->status = GenerationStatus::Failed;
            $this->markFailed($generation, $code, $message, null);
            $generation->save();
            $this->ledger->settle($generation);

            return $generation;
        });
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function hasBillingData(array $remote, Generation $generation): bool
    {
        return is_array($remote['usage'] ?? null) && is_array($remote['rates'] ?? $generation->rates);
    }

    private function isLateSuccessAfterWeGaveUp(Generation $generation, GenerationStatus $incoming): bool
    {
        return $incoming === GenerationStatus::Succeeded
            && $generation->status === GenerationStatus::Failed
            && in_array($generation->error_code, self::LOCAL_REJECTIONS, true)
            && ! $generation->provider_cost_pico;
    }

    /**
     * Store usage and what Pixelforge charged us, at the rates fixed on the generation when it was created.
     *
     * @param  array<string, mixed>  $remote
     */
    private function recordProviderUsage(Generation $generation, array $remote): void
    {
        $rates = $remote['rates'] ?? $generation->rates;

        $generation->usage = $remote['usage'];
        $generation->rates = $rates;
        $generation->provider_cost_pico = $this->prices->providerCost($remote['usage'], $rates);
        $generation->image_url = $remote['image_url'] ?? null;
        $generation->image_url_expires_at = isset($remote['image_url_expires_at']) ? Carbon::parse($remote['image_url_expires_at']) : null;
        $generation->completed_at = Carbon::parse($remote['completed_at'] ?? now());
    }

    private function markFailed(Generation $generation, string $code, string $message, ?string $completedAt): void
    {
        $generation->provider_cost_pico = 0;
        $generation->charged_pico = 0;
        $generation->error_code = $code;
        $generation->error_message = $message;
        $generation->completed_at = $completedAt ? Carbon::parse($completedAt) : now();
    }
}
