<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ApplyProviderState;
use App\Actions\FailGenerationLocally;
use App\Enums\FailureCode;
use App\Http\Clients\PixelforgeClient;
use App\Models\Generation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

/**
 * The safety net for webhooks, which are not guaranteed to arrive. Runs every minute from the
 * scheduler's own process, so its HTTP calls never hold up the queue workers.
 */
#[Signature('generations:poll')]
#[Description('Ask Pixelforge about unfinished generations with no recent news, and fail generations whose send was lost')]
final class PollGenerationsCommand extends Command
{
    /**
     * A third of the provider's 60 requests a minute, leaving the rest for sends.
     */
    public const int MAX_REQUESTS_PER_RUN = 20;

    public function handle(
        PixelforgeClient $pixelforge,
        ApplyProviderState $applyProviderState,
        FailGenerationLocally $failGenerationLocally
    ): int {
        $abandoned = Generation::query()->abandonedWhileWaiting()->get();

        foreach ($abandoned as $generation) {
            $this->line("Failing $generation->id: still waiting after ".Generation::ABANDONED_AFTER_MINUTES.' minutes.');
            $failGenerationLocally($generation, FailureCode::WaitTimeout);
        }

        $due = Generation::query()->dueForPolling()->limit(self::MAX_REQUESTS_PER_RUN)->get();
        $polledCount = 0;

        foreach ($due as $generation) {
            $this->line("Polling $generation->id ($generation->provider_id)...");
            $generation->touch('provider_synced_at');
            $polledCount++;

            try {
                $applyProviderState($pixelforge->getGeneration((string) $generation->provider_id));
            } catch (RequestException $exception) {
                if ($exception->response->tooManyRequests()) {
                    $this->warn('Pixelforge is rate-limiting us; the rest waits for the next run.');

                    break;
                }

                $this->warn("Could not fetch $generation->provider_id: HTTP {$exception->response->status()}.");
            } catch (ConnectionException|UnexpectedValueException $exception) {
                $this->warn("Could not fetch $generation->provider_id: {$exception->getMessage()}");
            }
        }

        $this->comment("Failed {$abandoned->count()} abandoned, polled $polledCount of {$due->count()} due.");

        return self::SUCCESS;
    }
}
