<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator report: what users were charged, what Pixelforge charged us, and the profit,
 * per UTC day and per user. Provider costs are checked against GET /v1/usage.
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        $days = (int) $request->integer('days', 14);
        $days = max(1, min($days, 92)); // Pixelforge allows at most 92 days per usage request.

        $end = CarbonImmutable::now('UTC')->startOfDay();
        $start = $end->subDays($days - 1);

        // Pixelforge bills a generation on the UTC day it completed, so we group the same way.
        $generations = Generation::query()
            ->with('user:id,name,email')
            // Everything Pixelforge billed us for, including a late success after we had given up.
            ->where('provider_cost_pico', '>', 0)
            ->whereBetween('completed_at', [$start, $end->endOfDay()])
            ->get();

        $byDay = $generations->groupBy(fn (Generation $g) => $g->completed_at->utc()->toDateString());

        [$usage, $usageError] = $this->usage($pixelforge, $start->toDateString(), $end->toDateString());

        $rows = collect();

        for ($day = $end; $day->gte($start); $day = $day->subDay()) {
            $date = $day->toDateString();
            $dayGenerations = $byDay->get($date, collect());
            $providerCost = (int) $dayGenerations->sum('provider_cost_pico');
            $pixelforgeCost = $usage[$date] ?? null;

            $rows->push([
                'date' => $date,
                'generations' => $dayGenerations->count(),
                ...$this->totals($dayGenerations),
                'pixelforge_generations' => $pixelforgeCost['generations'] ?? null,
                'pixelforge_cost' => $pixelforgeCost === null ? null : Money::format($pixelforgeCost['cost']),
                'reconciled' => $pixelforgeCost === null ? null : $pixelforgeCost['cost'] === $providerCost,
                'users' => $dayGenerations->groupBy('user_id')->map(fn (Collection $userGenerations) => [
                    'name' => $userGenerations->first()->user->name,
                    'email' => $userGenerations->first()->user->email,
                    'generations' => $userGenerations->count(),
                    ...$this->totals($userGenerations),
                ])->values(),
            ]);
        }

        return Inertia::render('admin/Report', [
            'days' => $days,
            'rows' => $rows,
            'totals' => [
                'generations' => $generations->count(),
                ...$this->totals($generations),
                'pixelforge_cost' => $usage === null ? null : Money::format(array_sum(array_column($usage, 'cost'))),
            ],
            'usageError' => $usageError,
        ]);
    }

    /**
     * @param  Collection<int, Generation>  $generations
     * @return array{charged: string, provider_cost: string, profit: string}
     */
    private function totals(Collection $generations): array
    {
        $charged = (int) $generations->sum('charged_pico');
        $providerCost = (int) $generations->sum('provider_cost_pico');

        return [
            'charged' => Money::format($charged),
            'provider_cost' => Money::format($providerCost),
            'profit' => Money::format($charged - $providerCost),
        ];
    }

    /**
     * @return array{0: array<string, array{generations: int, cost: int}>|null, 1: string|null}
     */
    private function usage(PixelforgeClient $pixelforge, string $start, string $end): array
    {
        try {
            $response = $pixelforge->usage($start, $end);

            $usage = [];

            foreach ($response['data'] ?? [] as $day) {
                $usage[$day['date']] = [
                    'generations' => (int) $day['generations'],
                    'cost' => Money::fromUsdString((string) $day['cost_usd']),
                ];
            }

            return [$usage, null];
        } catch (PixelforgeException|InvalidArgumentException $e) {
            return [null, 'Could not load Pixelforge usage: '.$e->getMessage()];
        }
    }
}
