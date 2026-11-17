<?php

use App\Services\Billing\PriceCalculator;
use App\Support\Money;

function pixelforgePricing(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);
}

test('provider cost is exact and matches the docs example', function () {
    $calculator = new PriceCalculator(25);

    $cost = $calculator->providerCost(
        ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184],
        pixelforgePricing()['rates'],
    );

    expect(Money::toUsdString($cost))->toBe('0.305510000000')
        ->and(Money::toUsdString($calculator->customerPrice($cost)))->toBe('0.381887500000');
});

test('the most expensive case matches the worked example in the docs', function () {
    $estimate = (new PriceCalculator(25))->estimate(str_repeat('a', 400), '1536x1024', 'high', 2, pixelforgePricing());

    expect(Money::toUsdString($estimate['provider_max']))->toBe('0.373875000000')
        ->and($estimate['max'])->toBe(Money::percentOf($estimate['provider_max'], 125));
});

test('the estimate range contains the expected price', function () {
    $estimate = (new PriceCalculator(25))->estimate('A lighthouse at dawn', '1024x1024', 'medium', 0, pixelforgePricing());

    expect($estimate['min'])->toBeLessThanOrEqual($estimate['expected'])
        ->and($estimate['expected'])->toBeLessThanOrEqual($estimate['max']);
});

test('money round-trips without losing precision', function () {
    expect(Money::fromUsdString('0.169080000'))->toBe(169_080_000_000)
        ->and(Money::toUsdString(169_080_000_000))->toBe('0.169080000000')
        ->and(Money::format(5 * Money::PICO_PER_DOLLAR))->toBe('$5.00')
        ->and(Money::format(46_125_000_000))->toBe('$0.046125');
});

test('markup rounds up to the next picodollar, never down', function () {
    expect(Money::percentOf(1, 125))->toBe(2)
        ->and(Money::percentOf(4, 125))->toBe(5);
});
