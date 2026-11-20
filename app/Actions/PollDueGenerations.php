<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\Generations\PollingResult;
use App\Dto\Pixelforge\GenerationData;
use App\Enums\FailureCode;
use App\Http\Clients\PixelforgeClient;
use App\Models\Generation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * The safety net for webhooks, which are not guaranteed to arrive: fails generations whose send
 * was lost, checks local failures the provider may hold after all, then asks the provider about
 * accepted generations with no recent news.
 */
final readonly class PollDueGenerations
{
    /**
     * A third of the provider's 60 requests a minute, leaving the rest for sends.
     */
    public const int MAX_REQUESTS_PER_RUN = 20;

    /**
     * Pages of the provider's list read for one check: 500 generations, the provider's daily
     * limit, so a failure from the last 24 hours cannot lie beyond them.
     */
    private const int MAX_LIST_PAGES = 5;

    public function __construct(
        private PixelforgeClient $pixelforge,
        private ApplyProviderState $applyProviderState,
        private FailGenerationLocally $failGenerationLocally,
    ) {}

    public function __invoke(): PollingResult
    {
        $abandoned = Generation::query()->abandonedWhileWaiting()->get();

        foreach ($abandoned as $generation) {
            ($this->failGenerationLocally)($generation, FailureCode::WaitTimeout);
        }

        ['requests' => $listRequests, 'recovered' => $recoveredCount, 'rateLimited' => $stoppedByRateLimit] = $this->verifyLocalFailures();

        $due = $stoppedByRateLimit
            ? new Collection
            : Generation::query()->dueForPolling()->limit(self::MAX_REQUESTS_PER_RUN - $listRequests)->get();
        $polledCount = 0;

        foreach ($due as $generation) {
            $polledCount++;

            try {
                ($this->applyProviderState)($this->pixelforge->getGeneration((string) $generation->provider_id));
            } catch (RequestException $exception) {
                if ($exception->response->tooManyRequests()) {
                    $stoppedByRateLimit = true;

                    break;
                }

                $this->skipUnfetchable($generation, "HTTP {$exception->response->status()}");
            } catch (ConnectionException|UnexpectedValueException $exception) {
                $this->skipUnfetchable($generation, $exception->getMessage());
            }
        }

        return new PollingResult(
            abandonedCount: $abandoned->count(),
            recoveredCount: $recoveredCount,
            dueCount: $due->count(),
            polledCount: $polledCount,
            stoppedByRateLimit: $stoppedByRateLimit,
        );
    }

    /**
     * A send that failed on our side may still have reached the provider: its answer was lost or
     * could not be read. Polling cannot see such a generation, which has no provider identifier,
     * so the provider's own list is searched for our identifiers. Whatever it holds replaces the
     * local failure; what it does not hold is marked as asked about and not looked for again.
     *
     * Nothing is marked unless the list was read as far as it needed to be, so a failed request
     * leaves the check for the next run. A failure is not looked at in its first two minutes: the
     * request we gave up on may still be on its way to the provider.
     *
     * A generation the provider holds in a state that cannot be read is left unchecked on purpose:
     * it is looked at again every run until the state can be read or the day is over.
     *
     * @return array{requests: int, recovered: int, rateLimited: bool}
     */
    private function verifyLocalFailures(): array
    {
        $unverified = array_flip(Generation::query()->unverifiedLocalFailures()->pluck('id')->all());

        if ($unverified === []) {
            return ['requests' => 0, 'recovered' => 0, 'rateLimited' => false];
        }

        $requests = 0;
        $recovered = 0;
        $cursor = null;

        try {
            do {
                $requests++;
                $page = $this->pixelforge->generations($cursor);

                foreach (array_intersect_key($page->generationsByLocalId, $unverified) as $localId => $held) {
                    unset($unverified[$localId]);

                    try {
                        ($this->applyProviderState)(GenerationData::fromArray($held));
                        $recovered++;
                    } catch (UnexpectedValueException $exception) {
                        Log::warning('Pixelforge holds a generation that failed locally, but its state could not be read.', [
                            'generation' => $localId,
                            'reason' => $exception->getMessage(),
                        ]);
                    }
                }

                $cursor = $page->nextCursor;
            } while ($cursor !== null && $unverified !== [] && $requests < self::MAX_LIST_PAGES);
        } catch (RequestException|ConnectionException|UnexpectedValueException $exception) {
            $response = $exception instanceof RequestException ? $exception->response : null;

            Log::warning('Could not list generations at Pixelforge to check local failures.', [
                'reason' => $response instanceof Response ? "HTTP {$response->status()}" : $exception->getMessage(),
            ]);

            return ['requests' => $requests, 'recovered' => $recovered, 'rateLimited' => (bool) $response?->tooManyRequests()];
        }

        Generation::query()->unverifiedLocalFailures()->whereKey(array_keys($unverified))->update(['provider_synced_at' => now()]);

        return ['requests' => $requests, 'recovered' => $recovered, 'rateLimited' => false];
    }

    /**
     * Counts the failed fetch as a check, so one broken generation cannot keep the head of the
     * polling order and starve the rest.
     */
    private function skipUnfetchable(Generation $generation, string $reason): void
    {
        Log::warning('Could not fetch a generation from Pixelforge while polling.', [
            'generation' => $generation->id,
            'provider_id' => $generation->provider_id,
            'reason' => $reason,
        ]);

        $generation->touch('provider_synced_at');
    }
}
