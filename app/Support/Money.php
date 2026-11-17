<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is stored and calculated as integer picodollars (1 USD = 10^12 picodollars).
 *
 * Why: Pixelforge reports costs with 9 decimal places and our 25% markup adds two more,
 * so a float would drift and a cent-based integer would lose precision. Picodollars keep
 * every amount exact, sum correctly in SQL, and fit easily in a 64-bit integer.
 */
final class Money
{
    public const SCALE = 12;

    public const PICO_PER_DOLLAR = 1_000_000_000_000;

    /**
     * Parse an exact decimal USD string (e.g. "0.046125000" or "12.50") into picodollars.
     */
    public static function fromUsdString(string $usd): int
    {
        $usd = trim($usd);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $usd, $m)) {
            throw new InvalidArgumentException("Invalid USD amount [{$usd}].");
        }

        $fraction = $m[3] ?? '';

        if (strlen(rtrim($fraction, '0')) > self::SCALE) {
            throw new InvalidArgumentException("USD amount [{$usd}] is more precise than a picodollar.");
        }

        $fraction = str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');
        $pico = (int) $m[2] * self::PICO_PER_DOLLAR + (int) $fraction;

        return $m[1] === '-' ? -$pico : $pico;
    }

    /**
     * Format picodollars as an exact USD string with all 12 decimals.
     */
    public static function toUsdString(int $pico): string
    {
        $sign = $pico < 0 ? '-' : '';
        $pico = abs($pico);

        return $sign.intdiv($pico, self::PICO_PER_DOLLAR).'.'
            .str_pad((string) ($pico % self::PICO_PER_DOLLAR), self::SCALE, '0', STR_PAD_LEFT);
    }

    /**
     * Human-friendly USD string: at least 2 decimals, trailing zeros trimmed, never rounded.
     * e.g. 46_125_000_000 => "$0.046125", 5 * 10^12 => "$5.00".
     */
    public static function format(int $pico): string
    {
        $exact = self::toUsdString($pico);
        [$whole, $fraction] = explode('.', ltrim($exact, '-'));
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, 2, '0');

        return ($pico < 0 ? '-' : '').'$'.$whole.'.'.$fraction;
    }

    /**
     * Multiply by a percentage and round UP to the next picodollar, so rounding can never
     * make us charge less than the exact amount.
     */
    public static function percentOf(int $pico, int $percent): int
    {
        $numerator = $pico * $percent;

        return intdiv($numerator, 100) + ($numerator % 100 > 0 ? 1 : 0);
    }
}
