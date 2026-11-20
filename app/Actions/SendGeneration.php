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
     * The shortest wait before sending again after a 429. The docs give 5 s for a busy slot.
     */
    private const int MIN_SLOT_WAIT_SECONDS = 5;

    /**
     * The longest wait before sending again after a 429, whatever the provider asks for: a bad
     * `Retry-After` cannot park a send for long, and the wait limit still applies.
     */
    private const int MAX_SLOT_WAIT_SECONDS = 60;

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
            return new SendRetry($this->slotWaitSeconds($response, $generation), $temporaryFailures);
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

    /**
     * How long to wait before sending again after a 429: as long as the generation has already
     * waited, so the gap doubles while the provider stays full, and not less than the provider
     * asked for, up to the longest wait. A generation that waits for minutes then costs one
     * request a minute, not twelve.
     */
    private function slotWaitSeconds(Response $response, Generation $generation): int
    {
        $retryAfter = $response->header('Retry-After');
        $askedFor = is_numeric($retryAfter) ? (int) $retryAfter : 0;
        $alreadyWaited = (int) $generation->created_at->diffInSeconds(now());

        return min(self::MAX_SLOT_WAIT_SECONDS, max(self::MIN_SLOT_WAIT_SECONDS, $askedFor, $alreadyWaited));
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        return is_string($message) ? $message : "HTTP {$response->status()}";
    }
}
