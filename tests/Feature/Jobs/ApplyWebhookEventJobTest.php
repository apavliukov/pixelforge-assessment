<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\ApplyWebhookEventJob;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pricing\Money;
use Illuminate\Support\Facades\Log;

function storedEvent(string $type, array $generation): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'type' => $type,
        'payload' => webhookEventBody($type, $generation),
    ]);
}

function runWebhookJob(WebhookEvent $event): void
{
    app()->call([new ApplyWebhookEventJob($event), 'handle']);
}

it('completes the generation and marks the event processed', function (): void {
    $generation = Generation::factory()->processing()->create();
    $event = storedEvent('generation.succeeded', providerGeneration('succeeded', [
        'id' => $generation->provider_id,
        'metadata' => ['local_id' => $generation->id],
    ]));

    runWebhookJob($event);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->user_charge)->toEqual(Money::fromNano(381_887_500))
        ->and($event->refresh()->processed_at)->not->toBeNull();
});

it('finds a generation by local identifier when the event arrives before the create response', function (): void {
    $generation = Generation::factory()->waiting()->create();
    $event = storedEvent('generation.processing', providerGeneration('processing', [
        'id' => 'gen_early',
        'metadata' => ['local_id' => $generation->id],
    ]));

    runWebhookJob($event);

    expect($generation->refresh()->provider_id)->toBe('gen_early')
        ->and($generation->status)->toBe(GenerationStatus::Processing);
});

it('marks an event for an unknown generation processed and logs it', function (): void {
    Log::spy();
    $event = storedEvent('generation.succeeded', providerGeneration('succeeded', ['id' => 'gen_unknown']));

    runWebhookJob($event);

    expect($event->refresh()->processed_at)->not->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

it('does not apply an event twice', function (): void {
    $generation = Generation::factory()->processing()->create();
    $event = storedEvent('generation.failed', providerGeneration('failed', [
        'id' => $generation->provider_id,
        'metadata' => ['local_id' => $generation->id],
    ]));
    $event->forceFill(['processed_at' => now()])->save();

    runWebhookJob($event);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});
