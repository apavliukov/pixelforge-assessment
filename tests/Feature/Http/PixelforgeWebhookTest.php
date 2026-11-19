<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\ApplyWebhookEventJob;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pricing\Money;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
});

describe('signature', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('accepts a request signed with the secret just now', function (): void {
        $body = webhookEventBody('ping', null);

        deliverWebhook($body, pixelforgeSignature($body))->assertOk();
    });

    it('rejects a request that is not correctly signed', function (Closure $signature): void {
        $body = webhookEventBody('generation.succeeded', providerGeneration());

        deliverWebhook($body, $signature($body))->assertStatus(400);

        expect(WebhookEvent::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'signature of another body' => [fn (string $body): string => pixelforgeSignature($body.' ')],
        'another secret' => [fn (string $body): string => pixelforgeSignature($body, secret: 'whsec_other')],
        'timestamp 6 minutes old' => [fn (string $body): string => pixelforgeSignature($body, time() - 360)],
        'timestamp 6 minutes ahead' => [fn (string $body): string => pixelforgeSignature($body, time() + 360)],
        'no header' => [fn (string $body): ?string => null],
        'malformed header' => [fn (string $body): string => 'sha256=abc'],
    ]);

    it('accepts a timestamp 4 minutes old', function (): void {
        $body = webhookEventBody('ping', null);

        deliverWebhook($body, pixelforgeSignature($body, time() - 240))->assertOk();
    });

    it('rejects everything when no secret is configured', function (): void {
        config(['services.pixelforge.webhook_secret' => '']);
        $body = webhookEventBody('ping', null);

        deliverWebhook($body, pixelforgeSignature($body, secret: ''))->assertStatus(400);
    });

    it('needs no session, login or CSRF token', function (): void {
        $body = webhookEventBody('ping', null);

        deliverWebhook($body, pixelforgeSignature($body))->assertOk()->assertCookieMissing(config('session.cookie'));
    });
});

describe('storing', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('stores a generation event with its raw body and queues it', function (): void {
        $body = webhookEventBody('generation.succeeded', providerGeneration());

        deliverWebhook($body, pixelforgeSignature($body))->assertOk();

        $event = WebhookEvent::query()->sole();
        expect($event->event_id)->toBe('evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A')
            ->and($event->type)->toBe('generation.succeeded')
            ->and($event->payload)->toBe($body)
            ->and($event->processed_at)->toBeNull();
        Queue::assertPushedOn('webhooks', ApplyWebhookEventJob::class, fn (ApplyWebhookEventJob $job): bool => $job->webhookEvent->is($event));
    });

    it('acknowledges a ping without storing it', function (): void {
        $body = webhookEventBody('ping', null);

        deliverWebhook($body, pixelforgeSignature($body))->assertOk();

        expect(WebhookEvent::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('acknowledges a repeated delivery without storing or queueing it again', function (): void {
        $body = webhookEventBody('generation.succeeded', providerGeneration());

        deliverWebhook($body, pixelforgeSignature($body))->assertOk();
        deliverWebhook($body, pixelforgeSignature($body))->assertOk();

        expect(WebhookEvent::query()->count())->toBe(1);
        Queue::assertPushed(ApplyWebhookEventJob::class, 1);
    });

    it('rejects a signed body that is not an event', function (): void {
        $body = '{"hello":"world"}';

        deliverWebhook($body, pixelforgeSignature($body))->assertStatus(400);
    });
});

it('records the cost once when the same success is delivered twice', function (): void {
    $generation = Generation::factory()->processing()->create();
    $body = webhookEventBody('generation.succeeded', providerGeneration('succeeded', [
        'id' => $generation->provider_id,
        'metadata' => ['local_id' => $generation->id],
    ]));

    deliverWebhook($body, pixelforgeSignature($body))->assertOk();
    deliverWebhook($body, pixelforgeSignature($body))->assertOk();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->user_charge)->toEqual(Money::fromNano(381_887_500))
        ->and(WebhookEvent::query()->sole()->processed_at)->not->toBeNull();
});
