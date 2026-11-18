<?php

declare(strict_types=1);

use App\Http\Clients\PixelforgeClient;
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

it('fails when Pixelforge answers with an error', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(status: 503)]);

    resolve(PixelforgeClient::class)->pricing();
})->throws(RequestException::class);
