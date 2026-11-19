<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\Pricing\PriceEstimate;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PricingUnavailableException;
use App\Pricing\PriceCalculator;
use App\Pricing\PricingCatalog;
use LogicException;

/**
 * The user charge range for a generation, at the current pricing and markup. The form shows it
 * before submission and `SubmitGeneration` records it, so both come from the same calculation.
 */
final readonly class EstimateGeneration
{
    private const int NO_MARKUP_BASIS_POINTS = 10_000;

    public function __construct(
        private PricingCatalog $catalog,
        private PriceCalculator $calculator,
    ) {}

    /**
     * Every generation is estimated before it is recorded, so the markup is checked here: one
     * below ×1 would charge users less than the provider bills, and must never reach a generation.
     *
     * @throws PricingUnavailableException when no recent pricing can be obtained
     * @throws LogicException when the configured markup is below ×1
     */
    public function __invoke(string $prompt, ImageSize $size, ImageQuality $quality, int $referenceImageCount): PriceEstimate
    {
        $priceMultiplierBasisPoints = (int) config('pricing.price_multiplier_basis_points');

        if ($priceMultiplierBasisPoints < self::NO_MARKUP_BASIS_POINTS) {
            throw new LogicException("The price multiplier must be at least 10000 basis points, got {$priceMultiplierBasisPoints}.");
        }

        return $this->calculator->estimate(
            prompt: $prompt,
            size: $size,
            quality: $quality,
            referenceImageCount: $referenceImageCount,
            pricing: $this->catalog->current(),
            priceMultiplierBasisPoints: $priceMultiplierBasisPoints,
        );
    }
}
