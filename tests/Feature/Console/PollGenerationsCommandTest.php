<?php

declare(strict_types=1);

use App\Models\Generation;
use Illuminate\Support\Facades\Http;

it('polls and prints a summary', function (): void {
    Http::preventStrayRequests();
    Generation::factory()->waiting()->create(['created_at' => now()->subMinutes(16)]);
    $heldByProvider = localFailure();
    fakeProviderGenerationList([providerGeneration('processing', ['id' => 'gen_held', 'metadata' => ['local_id' => $heldByProvider->id]])]);

    $this->artisan('generations:poll')
        ->expectsOutput('Failed 1 abandoned, found 1 local failures at Pixelforge, polled 0 of 0 due.')
        ->assertSuccessful();
});

it('warns when the provider rate-limits it', function (): void {
    Http::preventStrayRequests();
    Http::fake(['pixelforge.promptedit.dev/v1/generations/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429)]);
    Generation::factory()->processing()->create(['provider_synced_at' => now()->subMinutes(2)]);

    $this->artisan('generations:poll')
        ->expectsOutput('Pixelforge is rate-limiting us; the rest waits for the next run.')
        ->assertSuccessful();
});
