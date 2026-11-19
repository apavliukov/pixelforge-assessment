<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyProviderState;
use App\Actions\FailGenerationLocally;
use App\Enums\FailureCode;
use App\Enums\GenerationStatus;
use App\Http\Clients\PixelforgeClient;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * One attempt to send a waiting generation to the provider. Instead of being released back to
 * the queue, the job dispatches a delayed copy of itself: waiting for a free slot and retrying a
 * provider error are counted separately, which a single attempts counter cannot do. Every copy
 * sends the same idempotency key and body, so the provider never creates a second generation.
 */
#[Tries(1)]
#[Queue('submissions')]
final class SubmitGenerationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Delays before each retry after a 5xx or a network error; the last one is followed by failing.
     *
     * @var list<int>
     */
    public const array RETRY_DELAYS_SECONDS = [2, 4, 8, 16, 32];

    /**
     * Used when a 429 comes without a usable `Retry-After`.
     */
    private const int DEFAULT_RETRY_AFTER_SECONDS = 5;

    private const int MAX_RETRY_AFTER_SECONDS = 60;

    /**
     * @param  int  $temporaryFailures  how many earlier attempts failed with a 5xx or a network error
     */
    public function __construct(
        public Generation $generation,
        public int $temporaryFailures = 0,
    ) {}

    public function handle(PixelforgeClient $pixelforge, ApplyProviderState $applyProviderState, FailGenerationLocally $failGenerationLocally): void
    {
        $generation = $this->generation;

        if ($generation->status !== GenerationStatus::Waiting) {
            return;
        }

        if ($generation->hasWaitedTooLong()) {
            $failGenerationLocally($generation, FailureCode::WaitTimeout);

            return;
        }

        try {
            $this->send($generation, $pixelforge, $applyProviderState, $failGenerationLocally);
        } catch (Throwable $exception) {
            $failGenerationLocally($generation, FailureCode::SubmissionRejected, $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * An unexpected exception from here must not leave the generation waiting, so `handle()`
     * fails it and rethrows. If the provider did accept it, its next report replaces the failure.
     */
    private function send(
        Generation $generation,
        PixelforgeClient $pixelforge,
        ApplyProviderState $applyProviderState,
        FailGenerationLocally $failGenerationLocally,
    ): void {
        try {
            $state = $pixelforge->createGeneration($generation);
        } catch (ConnectionException $exception) {
            $this->retryOrGiveUp($generation, $failGenerationLocally, $exception->getMessage());

            return;
        } catch (RequestException $exception) {
            $this->handleErrorResponse($generation, $exception, $failGenerationLocally);

            return;
        }

        $applyProviderState($state);
    }

    private function handleErrorResponse(Generation $generation, RequestException $exception, FailGenerationLocally $failGenerationLocally): void
    {
        $response = $exception->response;
        $errorCode = $response->json('error.code');
        $errorMessage = $this->errorMessage($response);

        if ($response->serverError()) {
            $this->retryOrGiveUp($generation, $failGenerationLocally, $errorMessage);

            return;
        }

        if ($errorCode === 'daily_limit_exceeded') {
            $failGenerationLocally($generation, FailureCode::DailyLimitExceeded, $errorMessage);

            return;
        }

        if ($response->tooManyRequests()) {
            dispatch(new self($generation, $this->temporaryFailures))->delay($this->retryAfterSeconds($response));

            return;
        }

        if ($errorCode === 'content_policy_violation') {
            $failGenerationLocally($generation, FailureCode::ContentPolicyViolation, $errorMessage);

            return;
        }

        report($exception);
        $failGenerationLocally($generation, FailureCode::SubmissionRejected, $errorMessage);
    }

    private function retryOrGiveUp(Generation $generation, FailGenerationLocally $failGenerationLocally, string $reason): void
    {
        $delay = self::RETRY_DELAYS_SECONDS[$this->temporaryFailures] ?? null;

        if ($delay === null) {
            $failGenerationLocally($generation, FailureCode::ProviderUnavailable, $reason);

            return;
        }

        dispatch(new self($generation, $this->temporaryFailures + 1))->delay($delay);
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
