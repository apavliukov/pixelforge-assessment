<?php

declare(strict_types=1);

use App\Dto\Pixelforge\GenerationData;
use App\Enums\GenerationStatus;

it('reads a queued generation', function (): void {
    $generation = GenerationData::fromArray(providerGeneration('queued'));

    expect($generation->id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->localId)->toBe('019a4f2c-8d3e-7b10-9c55-2d8f1b4e7a10')
        ->and($generation->rates)->toBe(['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'])
        ->and($generation->usage)->toBeNull()
        ->and($generation->completedAt)->toBeNull();
});

it('reads the documented succeeded generation', function (): void {
    $generation = GenerationData::fromArray(providerGeneration());

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and([$generation->usage->inputText, $generation->usage->inputImage, $generation->usage->output, $generation->usage->reasoning])
        ->toBe([12, 765, 6_450, 3_184])
        ->and($generation->imageUrl)->toStartWith('https://pixelforge.promptedit.dev/images/')
        ->and($generation->imageUrlExpiresAt?->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z')
        ->and($generation->completedAt?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z');
});

it('reads a failed generation with its error', function (): void {
    $generation = GenerationData::fromArray(providerGeneration('failed'));

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->errorCode)->toBe('generation_failed')
        ->and($generation->errorMessage)->toBe('The model could not produce an image for this prompt.');
});

it('reads a generation sent without our metadata', function (): void {
    $generation = GenerationData::fromArray(providerGeneration('queued', ['metadata' => []]));

    expect($generation->localId)->toBeNull();
});

it('rejects a generation it could not apply safely', function (string $status, array $overrides): void {
    GenerationData::fromArray(providerGeneration($status, $overrides));
})->with([
    'succeeded without usage' => ['succeeded', ['usage' => null]],
    'usage with a negative count' => ['succeeded', ['usage' => ['input_text_tokens' => -1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0]]],
    'failed without an error' => ['failed', ['error' => null]],
    'unknown status' => ['cancelled', []],
    'our own waiting status' => ['waiting', []],
    'no identifier' => ['queued', ['id' => null]],
    'no rates' => ['queued', ['rates' => null]],
    'malformed rate' => ['queued', ['rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40,00', 'reasoning_tokens' => '12.50']]],
    'malformed timestamp' => ['succeeded', ['completed_at' => 'yesterday-ish']],
    'succeeded without a completion time' => ['succeeded', ['completed_at' => null]],
])->throws(UnexpectedValueException::class);
