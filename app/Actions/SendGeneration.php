<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\Generations\SendRetry;
use App\Enums\FailureCode;
use App\Enums\GenerationStatus;
use App\Http\Clients\PixelforgeClient;
use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * One attempt to send a waiting generation to the provider, and the decision what its answer
 * means: accepted, wait and try again, or failed at no cost. Every attempt sends the same
 * idempotency key and body, so the provider never creates a second generation.
 */
final readonly class SendGeneration
{
    /**
     * Delays before each retry after a 5xx or a network error; the last one is followed by failing.
     *
     * @var list<int>
     */
    public const array RETRY_DELAYS_SECONDS = [2, 4, 8, 16, 32];

    /**
     * Used when a 429 comes without a usable `Retry-After`. The docs give 5 s for a busy slot.
     */
    private const int DEFAULT_RETRY_AFTER_SECONDS = 5;

    /**
     * A `Retry-After` beyond this is treated as this, so a bad header cannot park a send for long;
     * the wait limit still applies.
     */
    private const int MAX_RETRY_AFTER_SECONDS = 60;

    public function __construct(
        private PixelforgeClient $pixelforge,
        private ApplyProviderState $applyProviderState,
        private FailGenerationLocally $failGenerationLocally,
    ) {}

    /**
     * An unexpected exception fails the generation and is rethrown, so it is not left waiting.
     * If the provider did accept it, its next report replaces the failure.
     *
     * @param  Generation  $generation  sent only while it is waiting
     * @param  int  $temporaryFailures  5xx and network failures of earlier attempts
     * @return SendRetry|null when to try again, or null when there is nothing more to do
     */
    public function __invoke(Generation $generation, int $temporaryFailures = 0): ?SendRetry
    {
        if ($generation->status !== GenerationStatus::Waiting) {
            return null;
        }

        if ($generation->hasWaitedTooLong()) {
            ($this->failGenerationLocally)($generation, FailureCode::WaitTimeout);

            return null;
        }

        try {
            return $this->attempt($generation, $temporaryFailures);
        } catch (Throwable $exception) {
            ($this->failGenerationLocally)($generation, FailureCode::SubmissionRejected, $exception->getMessage());

            throw $exception;
        }
    }

    private function attempt(Generation $generation, int $temporaryFailures): ?SendRetry
    {
        try {
            $state = $this->pixelforge->createGeneration($generation);
        } catch (ConnectionException $exception) {
            return $this->retryOrGiveUp($generation, $temporaryFailures, $exception->getMessage());
        } catch (RequestException $exception) {
            return $this->handleErrorResponse($generation, $temporaryFailures, $exception);
        }

        ($this->applyProviderState)($state);

        return null;
    }

    private function handleErrorResponse(Generation $generation, int $temporaryFailures, RequestException $exception): ?SendRetry
    {
        $response = $exception->response;
        $errorMessage = $this->errorMessage($response);
        $failureCode = FailureCode::tryFrom((string) $response->json('error.code'));

        if ($response->serverError()) {
            return $this->retryOrGiveUp($generation, $temporaryFailures, $errorMessage);
        }

        if ($failureCode === FailureCode::DailyLimitExceeded) {
            ($this->failGenerationLocally)($generation, $failureCode, $errorMessage);

            return null;
        }

        if ($response->tooManyRequests()) {
            return new SendRetry($this->retryAfterSeconds($response), $temporaryFailures);
        }

        if ($failureCode === FailureCode::ContentPolicyViolation) {
            ($this->failGenerationLocally)($generation, $failureCode, $errorMessage);

            return null;
        }

        report($exception);
        ($this->failGenerationLocally)($generation, FailureCode::SubmissionRejected, $errorMessage);

        return null;
    }

    private function retryOrGiveUp(Generation $generation, int $temporaryFailures, string $reason): ?SendRetry
    {
        $delay = self::RETRY_DELAYS_SECONDS[$temporaryFailures] ?? null;

        if ($delay === null) {
            ($this->failGenerationLocally)($generation, FailureCode::ProviderUnavailable, $reason);

            return null;
        }

        return new SendRetry($delay, $temporaryFailures + 1);
    }

    private function retryAfterSeconds(Response $response): int
    {
        $retryAfter = $response->header('Retry-After');

        if (! is_numeric($retryAfter)) {
            return self::DEFAULT_RETRY_AFTER_SECONDS;
        }

        return max(1, min(self::MAX_RETRY_AFTER_SECONDS, (int) $retryAfter));
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        return is_string($message) ? $message : "HTTP {$response->status()}";
    }
}
