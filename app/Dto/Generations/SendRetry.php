<?php

declare(strict_types=1);

namespace App\Dto\Generations;

/**
 * A send that did not reach a decision and must be tried again.
 */
final readonly class SendRetry
{
    /**
     * @param  int  $delaySeconds  how long to wait before the next attempt
     * @param  int  $temporaryFailures  5xx and network failures so far, including this attempt's
     */
    public function __construct(
        public int $delaySeconds,
        public int $temporaryFailures,
    ) {}
}
