<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DemoAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class DatabaseSeeder extends Seeder
{
    /**
     * Creates the demo accounts that do not exist yet. Safe to run on every start.
     *
     * A new account gets a random password that is recorded nowhere: it is reached through demo
     * sign-in only. An account is a demo account because this seeder says so in `demo_account`,
     * not because of its email: if someone already holds a demo account's email, that account is
     * left exactly as it is, the demo account is not created, and its sign-in button finds
     * nothing.
     *
     * Nothing else is seeded, and generations least of all: an invented one would carry a
     * provider cost the provider knows nothing about, and the operator report could never
     * reconcile.
     */
    public function run(): void
    {
        $accounts = collect(DemoAccount::cases());

        $taken = User::query()
            ->whereIn('demo_account', $accounts)
            ->orWhereIn('email', $accounts->map(static fn (DemoAccount $account): string => $account->email()))
            ->get(['demo_account', 'email']);

        foreach ($accounts as $account) {
            if ($taken->contains('demo_account', $account) || $taken->contains('email', $account->email())) {
                continue;
            }

            new User()->forceFill([
                'name' => $account->label(),
                'email' => $account->email(),
                'password' => Str::password(40),
                'email_verified_at' => now(),
                'is_operator' => $account->isOperator(),
                'demo_account' => $account,
            ])->save();
        }
    }
}
