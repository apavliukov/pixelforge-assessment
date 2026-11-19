<?php

declare(strict_types=1);

namespace App\Dto\OperatorReport;

use App\Pricing\Money;

/**
 * The money of some succeeded generations: one user's on one day, a day's, or a period's.
 */
final readonly class ReportFigures
{
    public function __construct(
        public int $generations,
        public Money $userCharge,
        public Money $providerCost,
    ) {}

    public static function zero(): self
    {
        return new self(0, Money::fromNano(0), Money::fromNano(0));
    }

    /**
     * @param  list<self>  $figures
     */
    public static function sum(array $figures): self
    {
        return array_reduce($figures, static fn (self $total, self $figure): self => $total->plus($figure), self::zero());
    }

    public function plus(self $other): self
    {
        return new self(
            $this->generations + $other->generations,
            $this->userCharge->plus($other->userCharge),
            $this->providerCost->plus($other->providerCost),
        );
    }

    /**
     * What the operator keeps. A generation is never recorded with a markup below ×1 (see
     * `EstimateGeneration`), so a user charge is never under its provider cost.
     */
    public function profit(): Money
    {
        return $this->userCharge->minus($this->providerCost);
    }

    /**
     * @return array{generations: int, userCharge: string, providerCost: string, profit: string}
     */
    public function toArray(): array
    {
        return [
            'generations' => $this->generations,
            'userCharge' => $this->userCharge->toDecimalString(),
            'providerCost' => $this->providerCost->toDecimalString(),
            'profit' => $this->profit()->toDecimalString(),
        ];
    }
}
