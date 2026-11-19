<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
});

function runSubmitJob(Generation $generation, int $temporaryFailures = 0): void
{
    app()->call([new SubmitGenerationJob($generation, $temporaryFailures), 'handle']);
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

    Queue::assertPushedOn('submissions', SubmitGenerationJob::class, fn (SubmitGenerationJob $job): bool => $job->delay === $delay
        && $job->temporaryFailures === $temporaryFailures
        && $job->generation->is($generation));
})->with([
    'no free slot keeps the failure count' => [429, 7, 1],
    'a provider error adds to it' => [503, 4, 2],
]);
