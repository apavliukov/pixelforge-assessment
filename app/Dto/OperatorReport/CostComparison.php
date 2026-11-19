<?php

declare(strict_types=1);

namespace App\Dto\OperatorReport;

use App\Pricing\Money;

/**
 * Our provider cost for some days next to what the provider reports for them.
 */
final readonly class CostComparison
{
    public function __construct(
        public Money $ourCost,
        public int $ourGenerations,
        public Money $providerCost,
        public int $providerGenerations,
    ) {}

    /**
     * Both must agree: equal costs with different numbers of generations still mean that the
     * two sides counted different generations.
     */
    public function matches(): bool
    {
        return $this->ourCost->nano === $this->providerCost->nano
            && $this->ourGenerations === $this->providerGenerations;
    }

    public function providerIsHigher(): bool
    {
        return $this->providerCost->nano > $this->ourCost->nano;
    }

    /**
     * How far apart the two costs are, whichever is higher.
     */
    public function difference(): Money
    {
        return $this->providerIsHigher()
            ? $this->providerCost->minus($this->ourCost)
            : $this->ourCost->minus($this->providerCost);
    }

    /**
     * @return array{ours: string, provider: string, generations: array{ours: int, provider: int}, matches: bool, difference: string, providerIsHigher: bool}
     */
    public function toArray(): array
    {
        return [
            'ours' => $this->ourCost->toDecimalString(),
            'provider' => $this->providerCost->toDecimalString(),
            'generations' => ['ours' => $this->ourGenerations, 'provider' => $this->providerGenerations],
            'matches' => $this->matches(),
            'difference' => $this->difference()->toDecimalString(),
            'providerIsHigher' => $this->providerIsHigher(),
        ];
    }
}
