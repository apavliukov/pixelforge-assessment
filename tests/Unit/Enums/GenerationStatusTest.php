<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;

it('orders statuses waiting, queued, processing, then the two finals', function (GenerationStatus $status, array $ahead): void {
    $actuallyAhead = array_values(array_filter(
        GenerationStatus::cases(),
        static fn (GenerationStatus $other): bool => $other->isAheadOf($status),
    ));

    expect($actuallyAhead)->toBe($ahead);
})->with([
    'waiting' => [GenerationStatus::Waiting, [GenerationStatus::Queued, GenerationStatus::Processing, GenerationStatus::Succeeded, GenerationStatus::Failed]],
    'queued' => [GenerationStatus::Queued, [GenerationStatus::Processing, GenerationStatus::Succeeded, GenerationStatus::Failed]],
    'processing' => [GenerationStatus::Processing, [GenerationStatus::Succeeded, GenerationStatus::Failed]],
    'succeeded' => [GenerationStatus::Succeeded, []],
    'failed' => [GenerationStatus::Failed, []],
]);

it('treats only succeeded and failed as final', function (): void {
    $final = array_values(array_filter(
        GenerationStatus::cases(),
        static fn (GenerationStatus $status): bool => $status->isFinal(),
    ));

    expect($final)->toBe([GenerationStatus::Succeeded, GenerationStatus::Failed]);
});

it('names each status in the user\'s words', function (): void {
    $labels = array_map(static fn (GenerationStatus $status): string => $status->label(), GenerationStatus::cases());

    expect($labels)->toBe(['Waiting to start', 'Queued', 'Generating', 'Done', 'Failed']);
});
