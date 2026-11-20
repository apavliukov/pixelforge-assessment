<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PollDueGenerations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every minute. Runs in the scheduler's own process, so its HTTP calls never hold up
 * the queue workers.
 */
#[Signature('generations:poll')]
#[Description('Fail generations whose send was lost, check local failures against Pixelforge, and ask it about unfinished generations with no recent news')]
final class PollGenerationsCommand extends Command
{
    public function handle(PollDueGenerations $pollDueGenerations): int
    {
        $result = $pollDueGenerations();

        if ($result->stoppedByRateLimit) {
            $this->warn('Pixelforge is rate-limiting us; the rest waits for the next run.');
        }

        $this->comment("Failed $result->abandonedCount abandoned, found $result->foundAtProviderCount local failures at Pixelforge, polled $result->polledCount of $result->dueCount due.");

        return self::SUCCESS;
    }
}
