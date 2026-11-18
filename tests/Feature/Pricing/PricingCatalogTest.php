<?php

declare(strict_types=1);

use App\Exceptions\PricingUnavailableException;
use App\Pricing\PricingCatalog;
use Illuminate\Support\Facades\Http;

function pricingWithOutputRate(string $rate): array
{
    $payload = publishedPricing();
    $payload['rates']['output_tokens'] = $rate;

    return $payload;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('uses the new pricing after a successful refresh', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::sequence()
        ->push(pricingWithOutputRate('40.00'))
        ->push(pricingWithOutputRate('50.00'))]);
    $catalog = resolve(PricingCatalog::class);
    $catalog->refresh();

    $catalog->refresh();

    expect($catalog->current()->rates->output->nano)->toBe(50_000);
});

it('keeps the previous pricing when the refresh fails', function (Closure $failingResponse): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::sequence()
        ->push(pricingWithOutputRate('40.00'))
        ->pushResponse($failingResponse())]);
    $catalog = resolve(PricingCatalog::class);
    $catalog->refresh();

    rescue(fn () => $catalog->refresh(), report: false);

    expect($catalog->current()->rates->output->nano)->toBe(40_000);
})->with([
    'Pixelforge unreachable' => [fn () => Http::response(status: 503)],
    'pricing without reasoning ranges' => [function () {
        $payload = publishedPricing();
        unset($payload['tokens']['reasoning']);

        return Http::response($payload);
    }],
]);

it('fetches and stores the pricing on first use', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(publishedPricing())]);
    $catalog = resolve(PricingCatalog::class);

    $first = $catalog->current();
    $second = $catalog->current();

    expect($first->payload)->toBe(publishedPricing())
        ->and($second->payload)->toBe(publishedPricing());
    Http::assertSentCount(1);
});

it('reports pricing as unavailable when there is no copy and Pixelforge is unreachable', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(status: 503)]);

    resolve(PricingCatalog::class)->current();
})->throws(PricingUnavailableException::class);

it('fetches fresh pricing once the local copy is over an hour old', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::sequence()
        ->push(pricingWithOutputRate('40.00'))
        ->push(pricingWithOutputRate('50.00'))]);
    $catalog = resolve(PricingCatalog::class);
    $catalog->refresh();
    $this->travel(61)->minutes();

    $pricing = $catalog->current();

    expect($pricing->rates->output->nano)->toBe(50_000);
});

it('reports pricing as unavailable when the copy is over an hour old and Pixelforge is unreachable', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::sequence()
        ->push(publishedPricing())
        ->pushStatus(503)]);
    $catalog = resolve(PricingCatalog::class);
    $catalog->refresh();
    $this->travel(61)->minutes();

    $catalog->current();
})->throws(PricingUnavailableException::class);
