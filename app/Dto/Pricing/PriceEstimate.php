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
}
