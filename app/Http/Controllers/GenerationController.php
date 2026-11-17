<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Services\Billing\PriceCalculator;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Generate', [
            'generations' => $user->generations()->latest('id')->paginate(20)
                ->through(fn (Generation $g) => $g->toPresentation()),
            'balance' => Money::format($user->balancePico()),
            'sizes' => StoreGenerationRequest::SIZES,
            'qualities' => StoreGenerationRequest::QUALITIES,
            'creditPacks' => CreditController::PACKS,
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        $createGeneration->handle($request->user(), $request->validated());

        // Page 1, where the brand-new generation shows up first.
        return to_route('generations.index');
    }

    /**
     * Live price shown while the user types. Only the prompt's length matters,
     * so the prompt itself is never sent here.
     */
    public function estimate(Request $request, PixelforgeClient $pixelforge, PriceCalculator $prices): JsonResponse
    {
        $data = $request->validate([
            'prompt_length' => ['required', 'integer', 'min:0', 'max:4000'],
            'size' => ['required', Rule::in(StoreGenerationRequest::SIZES)],
            'quality' => ['required', Rule::in(StoreGenerationRequest::QUALITIES)],
            'reference_images' => ['required', 'integer', 'min:0', 'max:4'],
        ]);

        $estimate = $prices->estimate(
            str_repeat('x', (int) $data['prompt_length']),
            $data['size'],
            $data['quality'],
            (int) $data['reference_images'],
            $pixelforge->pricing(),
        );

        return response()->json([
            'min' => Money::format($estimate['min']),
            'expected' => Money::format($estimate['expected']),
            'max' => Money::format($estimate['max']),
            'can_afford' => $request->user()->balancePico() >= $estimate['max'],
        ]);
    }
}
