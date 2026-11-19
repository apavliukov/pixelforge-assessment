<?php

declare(strict_types=1);

use App\Actions\PollDueGenerations;
use App\Dto\Generations\PollingResult;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const RETRIEVE_URL = 'pixelforge.promptedit.dev/v1/generations/*';

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->freezeSecond();
});

/**
 * Answers every retrieve request with the documented generation in the given status, under the
 * requested identifier.
 */
function fakeRetrieve(string $status): void
{
    Http::fake([RETRIEVE_URL => fn (Request $request) => Http::response(providerGeneration($status, [
        'id' => basename(parse_url($request->url(), PHP_URL_PATH)),
        'metadata' => [],
    ]))]);
}

function pollDueGenerations(): PollingResult
{
    return resolve(PollDueGenerations::class)();
}

function acceptedGeneration(int $submittedMinutesAgo, int $syncedSecondsAgo): Generation
{
    return Generation::factory()->processing()->create([
        'created_at' => now()->subMinutes($submittedMinutesAgo),
        'provider_synced_at' => now()->subSeconds($syncedSecondsAgo),
    ]);
}

describe('polling', function (): void {
    it('recovers a generation whose webhook was missed', function (): void {
        fakeRetrieve('succeeded');
        $generation = acceptedGeneration(submittedMinutesAgo: 3, syncedSecondsAgo: 90);

        pollDueGenerations();

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->user_charge)->toEqual(Money::fromNano(381_887_500));
        Http::assertSent(fn (Request $request): bool => $request->url() === "https://pixelforge.promptedit.dev/v1/generations/{$generation->provider_id}");
    });

    it('leaves alone a generation with recent news', function (int $submittedMinutesAgo, int $syncedSecondsAgo): void {
        fakeRetrieve('succeeded');
        $generation = acceptedGeneration($submittedMinutesAgo, $syncedSecondsAgo);

        pollDueGenerations();

        Http::assertNothingSent();
        expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
    })->with([
        'recent generation checked 20 seconds ago' => [3, 20],
        'old generation checked 5 minutes ago' => [60, 300],
    ]);

    it('asks about an old generation every 10 minutes', function (): void {
        fakeRetrieve('processing');
        $generation = acceptedGeneration(submittedMinutesAgo: 60, syncedSecondsAgo: 601);

        pollDueGenerations();

        Http::assertSentCount(1);
        expect($generation->refresh()->provider_synced_at?->equalTo(now()))->toBeTrue();
    });

    it('does not ask about waiting or finished generations', function (string $state): void {
        fakeRetrieve('succeeded');
        Generation::factory()->{$state}()->create([
            'created_at' => now()->subMinutes(5),
            'provider_synced_at' => now()->subMinutes(5),
        ]);

        pollDueGenerations();

        Http::assertNothingSent();
    })->with(['waiting', 'succeeded', 'failedLocally']);

    it('makes at most 20 requests per run, least recently checked first', function (): void {
        fakeRetrieve('processing');
        $stalest = acceptedGeneration(submittedMinutesAgo: 5, syncedSecondsAgo: 240);
        collect(range(1, 24))->each(fn (int $index): Generation => acceptedGeneration(submittedMinutesAgo: 5, syncedSecondsAgo: 120));

        pollDueGenerations();

        Http::assertSentCount(20);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), "/{$stalest->provider_id}"));
    });

    it('stops when the provider rate-limits it, without counting the refused request as a check', function (): void {
        Http::fake([RETRIEVE_URL => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down.']], 429, ['Retry-After' => '30'])]);
        $refused = acceptedGeneration(submittedMinutesAgo: 5, syncedSecondsAgo: 130);
        acceptedGeneration(submittedMinutesAgo: 5, syncedSecondsAgo: 120);

        $result = pollDueGenerations();

        Http::assertSentCount(1);
        expect($result->stoppedByRateLimit)->toBeTrue()
            ->and($result->polledCount)->toBe(1)
            ->and($refused->refresh()->provider_synced_at?->equalTo(now()->subSeconds(130)))->toBeTrue();
    });

    it('moves on past a generation it cannot fetch', function (): void {
        Http::fake([RETRIEVE_URL => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation.']], 404)]);
        $generation = acceptedGeneration(submittedMinutesAgo: 5, syncedSecondsAgo: 120);

        pollDueGenerations();

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Processing)
            ->and($generation->provider_synced_at?->equalTo(now()))->toBeTrue();
    });
});

describe('waiting generations', function (): void {
    it('fails a generation still waiting after 15 minutes', function (): void {
        $generation = Generation::factory()->waiting()->create(['created_at' => now()->subMinutes(16)]);

        pollDueGenerations();

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe('wait_timeout')
            ->and($generation->user_charge)->toEqual(Money::fromNano(0));
    });

    it('leaves a generation waiting for less than 15 minutes', function (): void {
        $generation = Generation::factory()->waiting()->create(['created_at' => now()->subMinutes(14)]);

        pollDueGenerations();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Waiting);
    });
});
