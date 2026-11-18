<?php

declare(strict_types=1);

use App\Dto\Pixelforge\TokenRates;

it('turns rates per 1M tokens into exact per-token prices', function (): void {
    $rates = TokenRates::fromArray([
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ]);

    expect([$rates->inputText->nano, $rates->inputImage->nano, $rates->output->nano, $rates->reasoning->nano])
        ->toBe([5_000, 10_000, 40_000, 12_500]);
});

it('rejects rates that are missing or not exact per token', function (array $override): void {
    TokenRates::fromArray([
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
        ...$override,
    ]);
})->with([
    'fraction of a nanodollar per token' => [['input_text_tokens' => '5.0005']],
    'number instead of a string' => [['output_tokens' => 40]],
    'comma as the decimal separator' => [['output_tokens' => '40,00']],
    'missing' => [['reasoning_tokens' => null]],
])->throws(UnexpectedValueException::class);
