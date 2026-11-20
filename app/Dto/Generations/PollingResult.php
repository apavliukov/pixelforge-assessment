<?php

declare(strict_types=1);

namespace App\Dto\Generations;

final readonly class PollingResult
{
    /**
     * @param  int  $abandonedCount  waiting generations failed because their send was lost
     * @param  int  $foundAtProviderCount  local failures the provider turned out to hold; its state has replaced each of them
     * @param  int  $dueCount  accepted generations due for polling in this run, whether or not the run got to them
     * @param  int  $polledCount  accepted generations the provider was asked about; requests for its list of generations are not counted
     * @param  bool  $stoppedByRateLimit  whether the provider answered 429 and the run stopped early
     */
    public function __construct(
        public int $abandonedCount,
        public int $foundAtProviderCount,
        public int $dueCount,
        public int $polledCount,
        public bool $stoppedByRateLimit,
    ) {}
}
