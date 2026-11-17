<?php

namespace App\Enums;

/**
 * Every change to a user's credit balance is one row in credit_ledger.
 * The balance is the sum of the amounts, so it can always be audited.
 */
enum LedgerEntryType: string
{
    // A fake credit purchase (+).
    case Purchase = 'purchase';
    // Reserve the most a generation could cost when it is submitted (-).
    case Hold = 'hold';
    // Give the reservation back once the generation is final (+).
    case Release = 'release';
    // The exact final price of a succeeded generation (-).
    case Charge = 'charge';
}
