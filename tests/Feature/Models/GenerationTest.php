<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\Money;

it('stores money columns as exact nanodollars', function (): void {
    $generation = Generation::factory()->succeeded()->create();

    $fresh = $generation->fresh();

    expect($fresh->estimate_max)->toEqual(Money::fromNano(21_831_250))
        ->and($fresh->provider_cost)->toEqual(Money::fromNano(305_510_000))
        ->and($fresh->user_charge)->toEqual(Money::fromNano(381_887_500));
});

it('builds every factory state', function (string $state, GenerationStatus $status, bool $hasProviderId): void {
    $generation = Generation::factory()->{$state}()->create()->fresh();

    expect($generation->status)->toBe($status)
        ->and($generation->provider_id !== null)->toBe($hasProviderId);
})->with([
    'waiting' => ['waiting', GenerationStatus::Waiting, false],
    'accepted' => ['accepted', GenerationStatus::Queued, true],
    'processing' => ['processing', GenerationStatus::Processing, true],
    'succeeded' => ['succeeded', GenerationStatus::Succeeded, true],
    'failed locally' => ['failedLocally', GenerationStatus::Failed, false],
]);

it('leaves costs unknown until the generation is finished', function (): void {
    $generation = Generation::factory()->processing()->create()->fresh();

    expect($generation->provider_cost)->toBeNull()
        ->and($generation->user_charge)->toBeNull();
});

it('refuses to store a plain number in a money column', function (): void {
    Generation::factory()->make(['estimate_max' => 21_831_250]);
})->throws(InvalidArgumentException::class, 'estimate_max');
