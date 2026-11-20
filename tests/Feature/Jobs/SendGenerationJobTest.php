<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\SendGenerationJob;
use App\Models\Generation;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
});

function runSubmitJob(Generation $generation, int $temporaryFailures = 0): void
{
    app()->call([new SendGenerationJob($generation, $temporaryFailures), 'handle']);
}

it('sends the generation and stops when the provider accepts it', function (): void {
    $generation = Generation::factory()->waiting()->create();
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response(providerGeneration('queued', ['metadata' => ['local_id' => $generation->id]]), 202)]);

    runSubmitJob($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    Queue::assertNothingPushed();
});

it('dispatches a delayed copy of itself when the send asks for a retry', function (int $status, int $delay, int $temporaryFailures): void {
    $generation = Generation::factory()->waiting()->create();
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response(['error' => ['code' => 'busy', 'message' => 'Busy.']], $status, ['Retry-After' => '7'])]);

    runSubmitJob($generation, 1);

    Queue::assertPushedOn('submissions', SendGenerationJob::class, fn (SendGenerationJob $job): bool => $job->delay === $delay
        && $job->temporaryFailures === $temporaryFailures
        && $job->generation->is($generation));
})->with([
    'no free slot keeps the failure count' => [429, 7, 1],
    'a provider error adds to it' => [503, 4, 2],
]);

it('may be run three times, for a run that a stopped worker left unfinished', function (): void {
    $tries = new ReflectionClass(SendGenerationJob::class)->getAttributes(Tries::class)[0]->newInstance();

    expect($tries->tries)->toBe(3);
});

it('sends nothing when the queue runs it again, with the generation loaded afresh, after the first run failed it', function (): void {
    $generation = Generation::factory()->waiting()->create();
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response(['error' => ['code' => 'content_policy_violation', 'message' => 'No.']], 400)]);

    runSubmitJob($generation);
    runSubmitJob($generation->fresh());

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed);
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
});
