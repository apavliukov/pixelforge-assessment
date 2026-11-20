<?php

declare(strict_types=1);

use App\Dto\OperatorReport\ReportPeriod;
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

it('fetches the usage report of a period as exact amounts per day', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/usage*' => Http::response([
        'object' => 'usage',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-02',
        'data' => [
            providerUsageFigures(3, '0.169080000') + ['date' => '2026-11-01'],
            providerUsageFigures(0, '0.000000000') + ['date' => '2026-11-02'],
        ],
        'total' => providerUsageFigures(3, '0.169080000'),
    ])]);

    $usage = resolve(PixelforgeClient::class)->usageReport(ReportPeriod::fromDates('2026-11-01', '2026-11-02'));

    expect(array_keys($usage->days))->toBe(['2026-11-01', '2026-11-02'])
        ->and($usage->days['2026-11-01']['generations'])->toBe(3)
        ->and($usage->days['2026-11-01']['cost']->nano)->toBe(169_080_000)
        ->and($usage->total['cost']->nano)->toBe(169_080_000);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-01&end_date=2026-11-02');
});

it('fetches a page of generations keyed by the local identifier they were created with', function (): void {
    fakeProviderGenerationList([
        providerGeneration('succeeded', ['id' => 'gen_ours', 'metadata' => ['local_id' => 'local-1']]),
        providerGeneration('queued', ['id' => 'gen_foreign', 'metadata' => []]),
    ], []);

    $page = resolve(PixelforgeClient::class)->generations();

    expect(array_keys($page->generationsByLocalId))->toBe(['local-1'])
        ->and($page->generationsByLocalId['local-1']['id'])->toBe('gen_ours')
        ->and($page->nextCursor)->toBe('page-2');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.promptedit.dev/v1/generations?limit=100');
});

it('asks for the next page with the cursor and knows the last page', function (): void {
    fakeProviderGenerationList([]);

    $page = resolve(PixelforgeClient::class)->generations('page-2');

    expect($page->nextCursor)->toBeNull()
        ->and($page->generationsByLocalId)->toBe([]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.promptedit.dev/v1/generations?limit=100&cursor=page-2');
});

it('refuses a list of generations that is not a list', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/generations?*' => Http::response(['object' => 'list'])]);

    resolve(PixelforgeClient::class)->generations();
})->throws(UnexpectedValueException::class);
