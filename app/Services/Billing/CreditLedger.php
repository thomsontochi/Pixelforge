<?php

namespace App\Services\Billing;

use App\Enums\LedgerEntryType;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Models\User;

/**
 * Writes balance changes. Each method is one ledger row, so a user's balance
 * is always the sum of their rows and every cent can be traced to a reason.
 */
class CreditLedger
{
    public function purchase(User $user, int $amountPico): CreditLedgerEntry
    {
        return $user->ledgerEntries()->create([
            'type' => LedgerEntryType::Purchase,
            'amount_pico' => $amountPico,
        ]);
    }

    /**
     * Reserve the most this generation could cost, so the user can't overspend
     * while it is still running.
     */
    public function hold(Generation $generation): void
    {
        $this->entry($generation, LedgerEntryType::Hold, -$generation->max_cost_pico);
    }

    /**
     * Settle a final generation: give back the hold and, if it succeeded, take the exact price.
     * firstOrCreate + the unique (generation_id, type) index make this safe to call twice.
     */
    public function settle(Generation $generation): void
    {
        $this->entry($generation, LedgerEntryType::Release, $generation->max_cost_pico);

        if ($generation->charged_pico !== null && $generation->charged_pico > 0) {
            $this->entry($generation, LedgerEntryType::Charge, -$generation->charged_pico);
        }
    }

    private function entry(Generation $generation, LedgerEntryType $type, int $amountPico): void
    {
        CreditLedgerEntry::firstOrCreate(
            ['generation_id' => $generation->id, 'type' => $type],
            ['user_id' => $generation->user_id, 'amount_pico' => $amountPico],
        );
    }
}
