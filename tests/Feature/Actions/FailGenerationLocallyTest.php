<?php

declare(strict_types=1);

use App\Actions\FailGenerationLocally;
use App\Enums\FailureCode;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\Money;

it('fails a waiting generation at no cost', function (): void {
    $generation = Generation::factory()->waiting()->create();

    resolve(FailGenerationLocally::class)($generation, FailureCode::ProviderUnavailable, 'Service Unavailable');

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('provider_unavailable')
        ->and($generation->error_message)->toBe('Service Unavailable')
        ->and($generation->provider_cost)->toEqual(Money::fromNano(0))
        ->and($generation->user_charge)->toEqual(Money::fromNano(0))
        ->and($generation->completed_at)->not->toBeNull();
});

it('leaves a generation the provider has accepted alone', function (): void {
    $generation = Generation::factory()->accepted()->create();

    resolve(FailGenerationLocally::class)($generation, FailureCode::WaitTimeout);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->error_code)->toBeNull();
});
