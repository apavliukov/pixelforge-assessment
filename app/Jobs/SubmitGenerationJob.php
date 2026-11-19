<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SendGeneration;
use App\Dto\Generations\SendRetry;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;

/**
 * Runs one send attempt. When the attempt asks to be retried, the job dispatches a delayed copy
 * of itself instead of being released back to the queue: waiting for a free slot and retrying a
 * provider error are counted separately, which a single attempts counter cannot do.
 */
#[Tries(1)]
#[Queue('submissions')]
final class SubmitGenerationJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Generation  $generation  the generation to send
     * @param  int  $temporaryFailures  5xx and network failures of earlier attempts
     */
    public function __construct(
        public Generation $generation,
        public int $temporaryFailures = 0,
    ) {}

    public function handle(SendGeneration $sendGeneration): void
    {
        $retry = $sendGeneration($this->generation, $this->temporaryFailures);

        if (! $retry instanceof SendRetry) {
            return;
        }

        dispatch(new self($this->generation, $retry->temporaryFailures))->delay($retry->delaySeconds);
    }
}
