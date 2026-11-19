<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Pricing\Money;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const CREATE_URL = 'pixelforge.promptedit.dev/v1/generations';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
});

function runSubmitJob(Generation $generation, int $temporaryFailures = 0): void
{
    app()->call([new SubmitGenerationJob($generation, $temporaryFailures), 'handle']);
}

/**
 * @param  array<string, string>  $headers
 */
function providerError(int $status, string $code, array $headers = []): PromiseInterface
{
    return Http::response(['error' => ['type' => 'test', 'code' => $code, 'message' => "Pixelforge says {$code}."]], $status, $headers);
}

describe('accepted', function (): void {
    it('records the provider identifier, rates and status', function (): void {
        $generation = Generation::factory()->waiting()->create();
        Http::fake([CREATE_URL => Http::response(providerGeneration('queued', ['id' => 'gen_new', 'metadata' => ['local_id' => $generation->id]]), 202)]);

        runSubmitJob($generation);

        $generation->refresh();
        expect($generation->provider_id)->toBe('gen_new')
            ->and($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->rates)->not->toBeNull();
        Queue::assertNothingPushed();
    });

    it('sends the same idempotency key and body on a repeated send', function (): void {
        $generation = Generation::factory()->waiting()->create();
        Http::fake([CREATE_URL => Http::sequence()
            ->push(['error' => ['code' => 'overloaded', 'message' => 'Overloaded.']], 503)
            ->push(providerGeneration('queued', ['metadata' => ['local_id' => $generation->id]]), 202)]);

        runSubmitJob($generation);
        runSubmitJob($generation, 1);

        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
        expect($requests)->toHaveCount(2)
            ->and($requests[1]->header('Idempotency-Key'))->toBe($requests[0]->header('Idempotency-Key'))
            ->and($requests[0]->header('Idempotency-Key'))->toBe([$generation->id])
            ->and($requests[1]->data())->toBe($requests[0]->data())
            ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('does not send a generation that is no longer waiting', function (string $state): void {
        $generation = Generation::factory()->{$state}()->create();

        runSubmitJob($generation);

        Http::assertNothingSent();
    })->with(['accepted', 'processing', 'succeeded', 'failedLocally']);
});

describe('no room at the provider', function (): void {
    it('sends again after the Retry-After delay', function (string $code): void {
        $generation = Generation::factory()->waiting()->create();
        Http::fake([CREATE_URL => providerError(429, $code, ['Retry-After' => '7'])]);

        runSubmitJob($generation, 2);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Waiting);
        Queue::assertPushedOn('submissions', SubmitGenerationJob::class, fn (SubmitGenerationJob $job): bool => $job->delay === 7
            && $job->temporaryFailures === 2
            && $job->generation->is($generation));
    })->with(['concurrency_limit_exceeded', 'rate_limit_exceeded']);

    it('waits a default delay when Retry-After is missing', function (): void {
        $generation = Generation::factory()->waiting()->create();
        Http::fake([CREATE_URL => providerError(429, 'rate_limit_exceeded')]);

        runSubmitJob($generation);

        Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job): bool => $job->delay === 5);
    });

    it('fails a generation that has waited 10 minutes without sending it', function (): void {
        $generation = Generation::factory()->waiting()->create(['created_at' => now()->subMinutes(10)->subSecond()]);

        runSubmitJob($generation);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe('wait_timeout');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    });
});

describe('temporary errors', function (): void {
    it('retries with growing delays', function (int $earlierFailures, int $delay): void {
        $generation = Generation::factory()->waiting()->create();
        Http::fake([CREATE_URL => providerError(503, 'overloaded', ['Retry-After' => '2'])]);

        runSubmitJob($generation, $earlierFailures);

        expect($generation->refresh()->status)->toBe(GenerationStatus::Waiting);
        Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job): bool => $job->delay === $delay
            && $job->temporaryFailures === $earlierFailures + 1);
    })->with([
        'first failure' => [0, 2],
        'second' => [1, 4],
        'third' => [2, 8],
        'fourth' => [3, 16],
        'fifth' => [4, 32],
    ]);

    it('treats a network error and any 5xx like overloaded', function (Closure $response): void {
        $generation = Generation::factory()->waiting()->create();
        Http::fake([CREATE_URL => $response]);

        runSubmitJob($generation);

        Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job): bool => $job->delay === 2);
    })->with([
        'connection error' => [fn (): never => throw new ConnectionException('Connection timed out')],
        '502' => [fn (): PromiseInterface => Http::response('Bad Gateway', 502)],
    ]);

    it('gives up after the fifth retry', function (): void {
        $generation = Generation::factory()->waiting()->create();
        Http::fake([CREATE_URL => providerError(503, 'overloaded')]);

        runSubmitJob($generation, 5);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe('provider_unavailable')
            ->and($generation->error_message)->toBe('Pixelforge says overloaded.');
        Queue::assertNothingPushed();
    });
});

describe('rejected', function (): void {
    it('fails at once with a code the user can be told about', function (int $status, string $providerCode, string $code): void {
        Exceptions::fake();
        $generation = Generation::factory()->waiting()->create();
        Http::fake([CREATE_URL => providerError($status, $providerCode)]);

        runSubmitJob($generation);

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe($code)
            ->and($generation->error_message)->toBe("Pixelforge says {$providerCode}.")
            ->and($generation->provider_cost)->toEqual(Money::fromNano(0))
            ->and($generation->user_charge)->toEqual(Money::fromNano(0));
        Queue::assertNothingPushed();
    })->with([
        'content policy' => [400, 'content_policy_violation', 'content_policy_violation'],
        'daily limit' => [429, 'daily_limit_exceeded', 'daily_limit_exceeded'],
        'invalid request' => [422, 'validation_failed', 'submission_rejected'],
        'bad key' => [401, 'invalid_api_key', 'submission_rejected'],
        'key reused' => [409, 'idempotency_key_reused', 'submission_rejected'],
    ]);

    it('reports a rejection caused by this app as a defect', function (): void {
        Exceptions::fake();
        Http::fake([CREATE_URL => providerError(422, 'validation_failed')]);

        runSubmitJob(Generation::factory()->waiting()->create());

        Exceptions::assertReportedCount(1);
    });

    it('does not report a rejection the user caused', function (): void {
        Exceptions::fake();
        Http::fake([CREATE_URL => providerError(400, 'content_policy_violation')]);

        runSubmitJob(Generation::factory()->waiting()->create());

        Exceptions::assertNothingReported();
    });

    it('fails the generation and rethrows on an unexpected error', function (): void {
        $generation = Generation::factory()->waiting()->create();
        Http::fake([CREATE_URL => Http::response(providerGeneration('queued', ['rates' => null]), 202)]);

        expect(fn () => runSubmitJob($generation))->toThrow(UnexpectedValueException::class, 'has no "rates"');

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe('submission_rejected')
            ->and($generation->user_charge)->toEqual(Money::fromNano(0));
    });
});
