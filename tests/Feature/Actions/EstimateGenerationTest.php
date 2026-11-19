<?php

declare(strict_types=1);

use App\Actions\EstimateGeneration;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PricingUnavailableException;
use App\Pricing\Money;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('estimates the documented example at the published pricing and configured markup', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(publishedPricing())]);

    $estimate = resolve(EstimateGeneration::class)(str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, 2);

    expect($estimate->min)->toEqual(Money::fromNano(357_781_250))
        ->and($estimate->max)->toEqual(Money::fromNano(467_343_750));
});

it('uses the configured markup', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(publishedPricing())]);
    config(['pricing.price_multiplier_basis_points' => 20_000]);

    $estimate = resolve(EstimateGeneration::class)(str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, 2);

    expect($estimate->max)->toEqual(Money::fromNano(747_750_000));
});

it('cannot estimate when no pricing can be obtained', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(status: 503)]);

    resolve(EstimateGeneration::class)('A lighthouse', ImageSize::Square, ImageQuality::Low, 0);
})->throws(PricingUnavailableException::class);
