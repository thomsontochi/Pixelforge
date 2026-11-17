<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Billing\PriceCalculator;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a generation: price it, check the user can afford the worst case, hold that
 * amount, then queue the submission to Pixelforge.
 */
class CreateGeneration
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly PriceCalculator $prices,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images?: array<int, string>}  $input
     */
    public function handle(User $user, array $input): Generation
    {
        $referenceImages = $input['reference_images'] ?? [];

        $estimate = $this->prices->estimate(
            $input['prompt'],
            $input['size'],
            $input['quality'],
            count($referenceImages),
            $this->pixelforge->pricing(),
        );

        $generation = DB::transaction(function () use ($user, $input, $referenceImages, $estimate) {
            // Lock the user row so two quick submits can't both spend the same balance.
            User::query()->lockForUpdate()->findOrFail($user->id);

            $balance = $user->balancePico();

            if ($balance < $estimate['max']) {
                throw ValidationException::withMessages([
                    'prompt' => 'Not enough credits: this can cost up to '.Money::format($estimate['max'])
                        .' and your balance is '.Money::format($balance).'. Buy credits to continue.',
                ]);
            }

            $generation = $user->generations()->create([
                'uuid' => (string) Str::uuid(),
                'status' => GenerationStatus::Pending,
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $referenceImages,
                'estimated_cost_pico' => $estimate['expected'],
                'max_cost_pico' => $estimate['max'],
            ]);

            $this->ledger->hold($generation);

            return $generation;
        });

        // The transaction above has committed, so the job always finds the row.
        SubmitGeneration::dispatch($generation->id);

        return $generation;
    }
}
