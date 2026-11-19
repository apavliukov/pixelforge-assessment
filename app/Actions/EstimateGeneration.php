<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\Pricing\PriceEstimate;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PricingUnavailableException;
use App\Pricing\PriceCalculator;
use App\Pricing\PricingCatalog;

/**
 * The user charge range for a generation, at the current pricing and markup. The form shows it
 * before submission and `SubmitGeneration` records it, so both come from the same calculation.
 */
final readonly class EstimateGeneration
{
    public function __construct(
        private PricingCatalog $catalog,
        private PriceCalculator $calculator,
    ) {}

    /**
     * @throws PricingUnavailableException when no recent pricing can be obtained
     */
    public function __invoke(string $prompt, ImageSize $size, ImageQuality $quality, int $referenceImageCount): PriceEstimate
    {
        return $this->calculator->estimate(
            prompt: $prompt,
            size: $size,
            quality: $quality,
            referenceImageCount: $referenceImageCount,
            pricing: $this->catalog->current(),
            priceMultiplierBasisPoints: (int) config('pricing.price_multiplier_basis_points'),
        );
    }
}
