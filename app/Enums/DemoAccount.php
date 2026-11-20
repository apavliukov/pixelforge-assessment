<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The accounts created by the seeder that anyone can sign in as while demo sign-in is on. A user
 * is one of them when the seeder wrote its key into `users.demo_account`; holding its email is
 * not enough.
 */
enum DemoAccount: string
{
    case Ada = 'ada';
    case Bob = 'bob';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Ada => 'Ada (demo user)',
            self::Bob => 'Bob (demo user)',
            self::Operator => 'Operator (demo)',
        };
    }

    public function email(): string
    {
        return "{$this->value}@example.com";
    }

    public function isOperator(): bool
    {
        return $this === self::Operator;
    }

    /**
     * What a visitor will see after signing in as this account.
     */
    public function description(): string
    {
        return $this->isOperator()
            ? 'The operator report: user charges, provider costs and profit, reconciled with Pixelforge.'
            : 'Generate images and see your own history and charges.';
    }
}
