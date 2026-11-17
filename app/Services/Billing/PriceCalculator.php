<?php

namespace App\Services\Billing;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Works out what a generation costs, from Pixelforge's published pricing.
 *
 * Pixelforge never returns a price, only token usage. Cost = sum(tokens x rate per token).
 * Rates are "USD per 1M tokens" (e.g. "12.50"). In picodollars that is exactly
 * rate * 10^6 per token, so every cost here is an exact integer.
 */
final class PriceCalculator
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private readonly int $markupPercent = 25) {}

    /**
     * The exact cost Pixelforge charges for a finished generation, in picodollars.
     *
     * @param  array<string, int>  $usage  token counts from the generation's `usage`
     * @param  array<string, string>  $rates  the rates fixed on the generation (USD per 1M tokens)
     */
    public function providerCost(array $usage, array $rates): int
    {
        $total = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $tokens = (int) ($usage[$type] ?? 0);

            if ($tokens > 0 && ! isset($rates[$type])) {
                // Never bill a token type as free just because its rate is missing.
                throw new InvalidArgumentException("No rate for [{$type}].");
            }

            $total += $tokens * $this->picoPerToken($rates[$type] ?? '0');
        }

        return $total;
    }

    /**
     * What the user pays: provider cost plus the markup (e.g. 25% => x1.25), rounded up to a picodollar.
     */
    public function customerPrice(int $providerCostPico): int
    {
        return Money::percentOf($providerCostPico, 100 + $this->markupPercent);
    }

    /**
     * Estimate a generation before it runs.
     *
     * Exact up front: output tokens (by size + quality) and image tokens (765 per reference image).
     * Only known afterwards: text tokens (ceil(chars / 4), real count within +/-15%) and
     * reasoning tokens (a min..max range by quality). So we return a range plus a midpoint.
     *
     * @param  array<string, mixed>  $pricing  the body of GET /v1/pricing
     * @return array{min: int, expected: int, max: int, provider_max: int, tokens: array<string, array{min: int, max: int}>}
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages, array $pricing): array
    {
        $tokens = $pricing['tokens'];
        $output = $tokens['output'][$quality][$size] ?? null;

        if ($output === null) {
            throw new InvalidArgumentException("No output token count for [{$quality}] [{$size}].");
        }

        $perChar = (int) $tokens['characters_per_text_token'];
        $variancePercent = (int) round(((float) $tokens['text_token_variance']) * 100);
        $textEstimate = (int) ceil(mb_strlen($prompt) / $perChar);

        $range = [
            'input_text_tokens' => [
                'min' => intdiv($textEstimate * (100 - $variancePercent), 100),
                'max' => (int) ceil($textEstimate * (100 + $variancePercent) / 100),
            ],
            'input_image_tokens' => [
                'min' => $referenceImages * (int) $tokens['per_reference_image'],
                'max' => $referenceImages * (int) $tokens['per_reference_image'],
            ],
            'output_tokens' => ['min' => (int) $output, 'max' => (int) $output],
            'reasoning_tokens' => [
                'min' => (int) $tokens['reasoning'][$quality]['min'],
                'max' => (int) $tokens['reasoning'][$quality]['max'],
            ],
        ];

        $minUsage = array_map(fn (array $r) => $r['min'], $range);
        $maxUsage = array_map(fn (array $r) => $r['max'], $range);
        $expectedUsage = array_map(fn (array $r) => intdiv($r['min'] + $r['max'], 2), $range);
        $expectedUsage['input_text_tokens'] = $textEstimate;

        $providerMax = $this->providerCost($maxUsage, $pricing['rates']);

        return [
            'min' => $this->customerPrice($this->providerCost($minUsage, $pricing['rates'])),
            'expected' => $this->customerPrice($this->providerCost($expectedUsage, $pricing['rates'])),
            'max' => $this->customerPrice($providerMax),
            'provider_max' => $providerMax,
            'tokens' => $range,
        ];
    }

    /**
     * "12.50" USD per 1M tokens => 12_500_000 picodollars per token.
     */
    private function picoPerToken(string $ratePerMillion): int
    {
        $perMillion = Money::fromUsdString($ratePerMillion);

        if ($perMillion % 1_000_000 !== 0) {
            // A rate with more than 6 decimals would make a single token cost a fraction of a picodollar.
            throw new InvalidArgumentException("Rate [{$ratePerMillion}] is too precise to bill exactly.");
        }

        return intdiv($perMillion, 1_000_000);
    }
}
