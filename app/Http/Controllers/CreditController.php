<?php

namespace App\Http\Controllers;

use App\Services\Billing\CreditLedger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use App\Models\CreditLedgerEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /** Dollar amounts offered on the "Buy credits" buttons. */
    public const PACKS = [1, 5, 20];

    /**
     * Every change to the balance, newest first: purchases, holds, releases and charges.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $entries = $user->ledgerEntries()
            ->with('generation:id,prompt,status')
            ->latest('id')
            ->paginate(50)
            ->through(fn (CreditLedgerEntry $entry) => [
                'id' => $entry->id,
                'type' => $entry->type->value,
                'amount' => Money::format($entry->amount_pico),
                'amount_exact' => Money::toUsdString($entry->amount_pico),
                'is_credit' => $entry->amount_pico > 0,
                'prompt' => $entry->generation?->prompt,
                'created_at' => $entry->created_at->toIso8601String(),
            ]);

        return Inertia::render('Credits', [
            'entries' => $entries,
            'balance' => Money::format($user->balancePico()),
            'creditPacks' => self::PACKS,
        ]);
    }

    /**
     * A fake purchase: no payment, it just adds credits to the user's ledger.
     */
    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'in:'.implode(',', self::PACKS)]]);

        $ledger->purchase($request->user(), (int) $data['amount'] * Money::PICO_PER_DOLLAR);

        return back();
    }
}
