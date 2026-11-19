<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\Generations\PollingResult;
use App\Enums\FailureCode;
use App\Http\Clients\PixelforgeClient;
use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * The safety net for webhooks, which are not guaranteed to arrive: fails generations whose send
 * was lost, then asks the provider about accepted generations with no recent news.
 */
final readonly class PollDueGenerations
{
    /**
     * A third of the provider's 60 requests a minute, leaving the rest for sends.
     */
    public const int MAX_REQUESTS_PER_RUN = 20;

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

        $due = Generation::query()->dueForPolling()->limit(self::MAX_REQUESTS_PER_RUN)->get();
        $polledCount = 0;
        $stoppedByRateLimit = false;

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
            dueCount: $due->count(),
            polledCount: $polledCount,
            stoppedByRateLimit: $stoppedByRateLimit,
        );
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
