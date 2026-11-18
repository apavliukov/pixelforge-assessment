<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Dto\Pixelforge\PricingData;
use App\Dto\Pixelforge\TokenRates;
use App\Dto\Pixelforge\TokenUsage;
use App\Dto\Pricing\PriceEstimate;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;

final readonly class PriceCalculator
{
    private const int BASIS_POINTS = 10_000;

    /**
     * What Pixelforge bills for a finished generation. Not rounded: it must match `cost_usd`.
     */
    public function providerCost(TokenUsage $usage, TokenRates $rates): Money
    {
        return $rates->inputText->times($usage->inputText)
            ->plus($rates->inputImage->times($usage->inputImage))
            ->plus($rates->output->times($usage->output))
            ->plus($rates->reasoning->times($usage->reasoning));
    }

    public function userCharge(Money $providerCost, int $markupBasisPoints): Money
    {
        return $providerCost->applyBasisPoints($markupBasisPoints);
    }

    /**
     * The user charge range for a generation that has not been submitted yet. The maximum is a
     * guarantee: the actual charge at the same rates cannot exceed it.
     */
    public function estimate(
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        int $referenceImageCount,
        PricingData $pricing,
        int $markupBasisPoints,
    ): PriceEstimate {
        $expectedTextTokens = $this->divideRoundingUp(mb_strlen($prompt), $pricing->charactersPerTextToken);
        $variance = $pricing->textTokenVarianceBasisPoints;
        $imageTokens = $referenceImageCount * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokens($quality, $size);
        $reasoningTokens = $pricing->reasoningTokens($quality);

        $fewest = new TokenUsage(
            inputText: intdiv($expectedTextTokens * (self::BASIS_POINTS - $variance), self::BASIS_POINTS),
            inputImage: $imageTokens,
            output: $outputTokens,
            reasoning: $reasoningTokens['min'],
        );

        $most = new TokenUsage(
            inputText: $this->divideRoundingUp($expectedTextTokens * (self::BASIS_POINTS + $variance), self::BASIS_POINTS),
            inputImage: $imageTokens,
            output: $outputTokens,
            reasoning: $reasoningTokens['max'],
        );

        return new PriceEstimate(
            min: $this->userCharge($this->providerCost($fewest, $pricing->rates), $markupBasisPoints),
            max: $this->userCharge($this->providerCost($most, $pricing->rates), $markupBasisPoints),
        );
    }

    private function divideRoundingUp(int $dividend, int $divisor): int
    {
        return intdiv($dividend + $divisor - 1, $divisor);
    }
}
