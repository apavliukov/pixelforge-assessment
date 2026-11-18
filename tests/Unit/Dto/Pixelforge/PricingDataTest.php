<?php

declare(strict_types=1);

use App\Dto\Pixelforge\PricingData;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;

it('reads the published pricing', function (): void {
    $pricing = PricingData::fromArray(publishedPricing());

    expect($pricing->rates->reasoning->nano)->toBe(12_500)
        ->and($pricing->charactersPerTextToken)->toBe(4)
        ->and($pricing->textTokenVarianceBasisPoints)->toBe(1_500)
        ->and($pricing->tokensPerReferenceImage)->toBe(765)
        ->and($pricing->outputTokens(ImageQuality::High, ImageSize::Landscape))->toBe(6_450)
        ->and($pricing->reasoningTokens(ImageQuality::Medium))->toBe(['min' => 200, 'max' => 2_000]);
});

it('rejects pricing that is missing or has a malformed value', function (Closure $break): void {
    $payload = publishedPricing();
    $break($payload);

    PricingData::fromArray($payload);
})->with([
    'no reasoning ranges' => [function (array &$payload): void {
        unset($payload['tokens']['reasoning']);
    }],
    'reasoning range upside down' => [function (array &$payload): void {
        $payload['tokens']['reasoning']['low'] = ['min' => 500, 'max' => 0];
    }],
    'output count as a string' => [function (array &$payload): void {
        $payload['tokens']['output']['low']['1024x1024'] = '280';
    }],
    'variance with more than four decimals' => [function (array &$payload): void {
        $payload['tokens']['text_token_variance'] = 0.15001;
    }],
    'zero characters per token' => [function (array &$payload): void {
        $payload['tokens']['characters_per_text_token'] = 0;
    }],
    'no rates' => [function (array &$payload): void {
        unset($payload['rates']);
    }],
])->throws(UnexpectedValueException::class);

it('refuses to look up an output count the pricing does not publish', function (): void {
    $payload = publishedPricing();
    unset($payload['tokens']['output']['medium']['1024x1536']);

    PricingData::fromArray($payload)->outputTokens(ImageQuality::Medium, ImageSize::Portrait);
})->throws(UnexpectedValueException::class, 'medium quality at 1024x1536');
