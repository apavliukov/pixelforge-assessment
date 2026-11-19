<?php

declare(strict_types=1);

use App\Dto\Pixelforge\PricingData;
use App\Dto\Pixelforge\TokenUsage;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Pricing\PriceCalculator;

it('sums every token type at the generation\'s own rates', function (TokenUsage $usage, int $expected): void {
    $rates = PricingData::fromArray(publishedPricing())->rates;

    expect(new PriceCalculator()->providerCost($usage, $rates)->nano)->toBe($expected);
})->with([
    'worked example' => [new TokenUsage(115, 1_530, 6_450, 8_000), 373_875_000],
    'documented succeeded generation' => [new TokenUsage(12, 765, 6_450, 3_184), 305_510_000],
]);

it('estimates the user charge as a range including the markup', function (
    string $prompt,
    ImageSize $size,
    ImageQuality $quality,
    int $referenceImageCount,
    int $min,
    int $max,
): void {
    $estimate = new PriceCalculator()->estimate(
        prompt: $prompt,
        size: $size,
        quality: $quality,
        referenceImageCount: $referenceImageCount,
        pricing: PricingData::fromArray(publishedPricing()),
        priceMultiplierBasisPoints: 12_500,
    );

    expect([$estimate->min->nano, $estimate->max->nano])->toBe([$min, $max]);
})->with([
    'documented example' => [str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, 2, 357_781_250, 467_343_750],
    'no reference images' => [str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, 0, 338_656_250, 448_218_750],
    'five emoji count as five characters' => ['🙂🙂🙂🙂🙂', ImageSize::Square, ImageQuality::Low, 0, 14_006_250, 21_831_250],
]);

it('refuses to estimate a quality and size the pricing does not publish', function (): void {
    $payload = publishedPricing();
    unset($payload['tokens']['output']['high']['1024x1024']);

    new PriceCalculator()->estimate(
        prompt: 'A lighthouse',
        size: ImageSize::Square,
        quality: ImageQuality::High,
        referenceImageCount: 0,
        pricing: PricingData::fromArray($payload),
        priceMultiplierBasisPoints: 12_500,
    );
})->throws(UnexpectedValueException::class, 'high quality at 1024x1024');
