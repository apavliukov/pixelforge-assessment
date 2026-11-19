<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Clients\PixelforgeClient;
use App\Models\Generation;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('fetches the published pricing', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(publishedPricing())]);

    $pricing = resolve(PixelforgeClient::class)->pricing();

    expect($pricing->payload)->toBe(publishedPricing());
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.promptedit.dev/v1/pricing');
});

it('creates a generation keyed by its local identifier', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response(providerGeneration('queued'), 202)]);
    $generation = Generation::factory()->create([
        'prompt' => 'A lighthouse',
        'size' => ImageSize::Landscape,
        'quality' => ImageQuality::High,
        'reference_images' => ['https://example.com/style.png'],
    ]);

    $created = resolve(PixelforgeClient::class)->createGeneration($generation);

    expect($created->status)->toBe(GenerationStatus::Queued);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
        && $request->header('Idempotency-Key') === [$generation->id]
        && $request->data() === [
            'prompt' => 'A lighthouse',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['local_id' => $generation->id],
        ]);
});

it('retrieves a generation by the provider identifier', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response(providerGeneration())]);

    $generation = resolve(PixelforgeClient::class)->getGeneration('gen_1');

    expect($generation->status)->toBe(GenerationStatus::Succeeded);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations/gen_1');
});

it('fails when Pixelforge answers with an error', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(status: 503)]);

    resolve(PixelforgeClient::class)->pricing();
})->throws(RequestException::class);
