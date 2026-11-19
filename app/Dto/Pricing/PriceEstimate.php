<?php

declare(strict_types=1);

namespace App\Dto\Pricing;

use App\Pricing\Money;

final readonly class PriceEstimate
{
    public function __construct(
        public Money $min,
        public Money $max,
    ) {}

    /**
     * @return array{min: array{formatted: string, exact: string}, max: array{formatted: string, exact: string}}
     */
    public function toDisplay(): array
    {
        return ['min' => $this->min->toDisplay(), 'max' => $this->max->toDisplay()];
    }
}
