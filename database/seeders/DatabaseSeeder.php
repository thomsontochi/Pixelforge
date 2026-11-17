<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Support\Money;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * A demo operator account for reviewers: can generate and open the admin report.
     */
    public function run(CreditLedger $ledger): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'demo@pixelforge.test'],
            [
                'name' => 'Demo Operator',
                'password' => 'pixelforge-demo',
                'email_verified_at' => now(),
            ],
        );

        $user->forceFill(['is_admin' => true])->save();

        if ($user->balancePico() === 0) {
            $ledger->purchase($user, 10 * Money::PICO_PER_DOLLAR);
        }
    }
}
