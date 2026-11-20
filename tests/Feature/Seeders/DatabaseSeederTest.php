<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('creates two demo users and one demo operator', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->orderBy('email')->get(['name', 'email', 'is_operator', 'demo_account'])->toArray())->toBe([
        ['name' => 'Ada (demo user)', 'email' => 'ada@example.com', 'is_operator' => false, 'demo_account' => 'ada'],
        ['name' => 'Bob (demo user)', 'email' => 'bob@example.com', 'is_operator' => false, 'demo_account' => 'bob'],
        ['name' => 'Operator (demo)', 'email' => 'operator@example.com', 'is_operator' => true, 'demo_account' => 'operator'],
    ]);
});

it('creates no duplicates and keeps the passwords when run again', function (): void {
    $this->seed(DatabaseSeeder::class);
    $passwords = User::query()->pluck('password', 'email')->all();

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(3)
        ->and(User::query()->pluck('password', 'email')->all())->toBe($passwords);
});

it('leaves alone someone who already holds a demo account\'s email, and creates no demo account in their place', function (): void {
    $holder = User::factory()->create(['email' => 'operator@example.com']);

    $this->seed(DatabaseSeeder::class);

    expect($holder->fresh()->only(['name', 'password', 'is_operator', 'demo_account']))
        ->toBe(['name' => $holder->name, 'password' => $holder->password, 'is_operator' => false, 'demo_account' => null])
        ->and(User::query()->whereNotNull('demo_account')->pluck('email')->sort()->values()->all())->toBe(['ada@example.com', 'bob@example.com'])
        ->and(User::query()->where('is_operator', true)->exists())->toBeFalse();
});

it('creates no generations', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Generation::query()->count())->toBe(0);
});
