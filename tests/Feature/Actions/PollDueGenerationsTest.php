<?php

declare(strict_types=1);

use App\Actions\PollDueGenerations;
use App\Dto\Generations\PollingResult;
use App\Enums\FailureCode;
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

describe('local failures the provider may hold', function (): void {
    it('replaces a local failure of any kind with the state the provider holds', function (FailureCode $code): void {
        $generation = localFailure($code);
        fakeProviderGenerationList([
            providerGeneration('queued', ['id' => 'gen_other', 'metadata' => ['local_id' => 'someone-else']]),
            providerGeneration('succeeded', ['id' => 'gen_lost', 'metadata' => ['local_id' => $generation->id]]),
        ]);

        $result = pollDueGenerations();

        $generation->refresh();
        expect($result->foundAtProviderCount)->toBe(1)
            ->and($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->provider_id)->toBe('gen_lost')
            ->and($generation->error_code)->toBeNull()
            ->and($generation->provider_cost?->isZero())->toBeFalse()
            ->and($generation->user_charge?->isZero())->toBeFalse()
            ->and($generation->output_tokens)->not->toBeNull();
    })->with(FailureCode::cases());

    it('asks once about a generation the provider never received', function (): void {
        $generation = localFailure();
        fakeProviderGenerationList([providerGeneration('succeeded', ['id' => 'gen_other', 'metadata' => ['local_id' => 'someone-else']])]);

        $result = pollDueGenerations();
        pollDueGenerations();

        $generation->refresh();
        expect($result->foundAtProviderCount)->toBe(0)
            ->and($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe('provider_unavailable')
            ->and($generation->provider_synced_at)->not->toBeNull();
        Http::assertSentCount(1);
    });

    it('does not ask the provider when there is nothing to check', function (Closure $existing): void {
        $existing();

        pollDueGenerations();

        Http::assertNothingSent();
    })->with([
        'no generations at all' => [fn (): null => null],
        'failed more than 24 hours ago' => [fn (): Generation => localFailure(attributes: ['completed_at' => now()->subHours(24)->subSecond()])],
        'failed less than two minutes ago' => [fn (): Generation => localFailure(attributes: ['completed_at' => now()->subMinutes(2)->addSecond()])],
        'already checked' => [fn (): Generation => localFailure(attributes: ['provider_synced_at' => now()->subMinute()])],
        'failed by the provider' => [fn (): Generation => localFailure(attributes: ['provider_id' => 'gen_known'])],
    ]);

    it('checks again next time when the provider cannot be asked', function (int $status): void {
        $generation = localFailure();
        Http::fake([PROVIDER_GENERATION_LIST_URL => Http::sequence()
            ->push(['error' => ['code' => 'overloaded']], $status)
            ->push(providerGenerationPage([providerGeneration('succeeded', ['id' => 'gen_lost', 'metadata' => ['local_id' => $generation->id]])]))]);

        pollDueGenerations();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
            ->and($generation->provider_synced_at)->toBeNull();

        pollDueGenerations();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
    })->with(['overloaded' => 503, 'unauthorised' => 401]);

    it('stops the whole run when the provider rate-limits the list', function (): void {
        localFailure();
        acceptedGeneration(submittedMinutesAgo: 3, syncedSecondsAgo: 90);
        Http::fake([PROVIDER_GENERATION_LIST_URL => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429)]);

        $result = pollDueGenerations();

        expect($result->stoppedByRateLimit)->toBeTrue()
            ->and($result->polledCount)->toBe(0);
        Http::assertSentCount(1);
    });

    it('reads the next page when the generation is not on the first', function (): void {
        $generation = localFailure();
        fakeProviderGenerationList(
            [providerGeneration('succeeded', ['id' => 'gen_newer', 'metadata' => ['local_id' => 'someone-else']])],
            [providerGeneration('succeeded', ['id' => 'gen_lost', 'metadata' => ['local_id' => $generation->id]])],
        );

        pollDueGenerations();

        expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
        $urls = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all();
        expect($urls)->toBe([
            'https://pixelforge.promptedit.dev/v1/generations?limit=100',
            'https://pixelforge.promptedit.dev/v1/generations?limit=100&cursor=page-2',
        ]);
    });

    it('reads no further once every generation is found', function (): void {
        $generation = localFailure();
        fakeProviderGenerationList(
            [providerGeneration('succeeded', ['id' => 'gen_lost', 'metadata' => ['local_id' => $generation->id]])],
            [],
        );

        pollDueGenerations();

        Http::assertSentCount(1);
    });

    it('reads at most ten pages, then stops looking', function (): void {
        $generation = localFailure();
        fakeProviderGenerationList(...array_fill(0, 11, []));

        pollDueGenerations();

        expect($generation->refresh()->provider_synced_at)->not->toBeNull();
        Http::assertSentCount(10);
    });

    it('counts a local failure the provider itself ended in failure as found, at no charge', function (): void {
        $generation = localFailure();
        fakeProviderGenerationList([providerGeneration('failed', ['id' => 'gen_lost', 'metadata' => ['local_id' => $generation->id]])]);

        $result = pollDueGenerations();

        $generation->refresh();
        expect($result->foundAtProviderCount)->toBe(1)
            ->and($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->provider_id)->toBe('gen_lost')
            ->and($generation->user_charge)->toEqual(Money::fromNano(0));
    });

    it('still reports how many generations were due when the provider rate-limits the list', function (): void {
        localFailure();
        acceptedGeneration(submittedMinutesAgo: 3, syncedSecondsAgo: 90);
        acceptedGeneration(submittedMinutesAgo: 3, syncedSecondsAgo: 120);
        Http::fake([PROVIDER_GENERATION_LIST_URL => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429)]);

        $result = pollDueGenerations();

        expect($result->dueCount)->toBe(2)
            ->and($result->polledCount)->toBe(0);
    });

    it('leaves a generation unchecked when the provider holds it in a state that cannot be read', function (): void {
        $generation = localFailure();
        fakeProviderGenerationList([providerGeneration('succeeded', ['id' => 'gen_lost', 'usage' => null, 'metadata' => ['local_id' => $generation->id]])]);

        $result = pollDueGenerations();

        expect($result->foundAtProviderCount)->toBe(0)
            ->and($generation->refresh()->status)->toBe(GenerationStatus::Failed)
            ->and($generation->provider_synced_at)->toBeNull();
    });

    it('checks a generation it failed for waiting too long, two minutes after failing it', function (): void {
        $generation = Generation::factory()->waiting()->create(['created_at' => now()->subMinutes(16)]);
        fakeProviderGenerationList([providerGeneration('processing', ['id' => 'gen_lost', 'metadata' => ['local_id' => $generation->id]])]);

        $failing = pollDueGenerations();

        expect($failing->abandonedCount)->toBe(1)
            ->and($failing->foundAtProviderCount)->toBe(0)
            ->and($generation->refresh()->status)->toBe(GenerationStatus::Failed);
        Http::assertNothingSent();

        $this->travel(2)->minutes();
        $checking = pollDueGenerations();

        expect($checking->foundAtProviderCount)->toBe(1)
            ->and($generation->refresh()->status)->toBe(GenerationStatus::Processing);
    });

    it('asks for the list first and keeps the run within 20 requests', function (): void {
        localFailure();
        fakeProviderGenerationList([]);
        fakeRetrieve('processing');

        foreach (range(1, 25) as $number) {
            acceptedGeneration(submittedMinutesAgo: 3, syncedSecondsAgo: 60 + $number);
        }

        $result = pollDueGenerations();

        $urls = Http::recorded()->map(fn (array $pair): string => $pair[0]->url());
        expect($urls)->toHaveCount(20)
            ->and($urls->first())->toBe('https://pixelforge.promptedit.dev/v1/generations?limit=100')
            ->and($result->polledCount)->toBe(19);
    });
});
