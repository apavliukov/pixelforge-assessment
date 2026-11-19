<?php

declare(strict_types=1);

namespace App\Dto\Pixelforge;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

/**
 * The provider's generation object, as returned by the create and retrieve endpoints and carried
 * in webhook events. Validated so that applying it can never record a success without money.
 */
final readonly class GenerationData
{
    /**
     * @param  string  $id  the provider's `gen_…` identifier
     * @param  GenerationStatus  $status  one of the provider's four statuses, never waiting
     * @param  string|null  $localId  our identifier, sent as `metadata.local_id`
     * @param  TokenRates  $rates  the rates the generation is billed at
     * @param  array<string, string>  $ratesPayload  the same rates per 1M tokens, as received
     * @param  TokenUsage|null  $usage  set when succeeded
     * @param  string|null  $errorCode  set when failed
     * @param  string|null  $errorMessage  set when failed
     * @param  string|null  $imageUrl  set when succeeded
     * @param  CarbonImmutable|null  $imageUrlExpiresAt  when the image URL stops working
     * @param  CarbonImmutable|null  $completedAt  when it succeeded or failed
     */
    private function __construct(
        public string $id,
        public GenerationStatus $status,
        public ?string $localId,
        public TokenRates $rates,
        public array $ratesPayload,
        public ?TokenUsage $usage,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $generation
     *
     * @throws UnexpectedValueException when the object is malformed or inconsistent with its status
     */
    public static function fromArray(array $generation): self
    {
        $id = $generation['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new UnexpectedValueException('Generation has no "id".');
        }

        $status = GenerationStatus::tryFrom(is_string($generation['status'] ?? null) ? $generation['status'] : '');

        if ($status === null || $status === GenerationStatus::Waiting) {
            throw new UnexpectedValueException("Generation {$id} has an unknown status.");
        }

        $ratesPayload = $generation['rates'] ?? null;

        if (! is_array($ratesPayload)) {
            throw new UnexpectedValueException("Generation {$id} has no \"rates\".");
        }

        $usage = $generation['usage'] ?? null;

        if ($status === GenerationStatus::Succeeded && ! is_array($usage)) {
            throw new UnexpectedValueException("Generation {$id} succeeded without \"usage\".");
        }

        $error = $generation['error'] ?? null;

        if ($status === GenerationStatus::Failed && ! is_string($error['code'] ?? null)) {
            throw new UnexpectedValueException("Generation {$id} failed without an error code.");
        }

        $localId = $generation['metadata']['local_id'] ?? null;
        $imageUrl = $generation['image_url'] ?? null;
        $errorMessage = $error['message'] ?? null;

        return new self(
            id: $id,
            status: $status,
            localId: is_string($localId) ? $localId : null,
            rates: TokenRates::fromArray($ratesPayload),
            ratesPayload: $ratesPayload,
            usage: is_array($usage) ? TokenUsage::fromArray($usage) : null,
            errorCode: $status === GenerationStatus::Failed ? $error['code'] : null,
            errorMessage: is_string($errorMessage) ? $errorMessage : null,
            imageUrl: is_string($imageUrl) ? $imageUrl : null,
            imageUrlExpiresAt: self::timestamp($generation, 'image_url_expires_at'),
            completedAt: self::timestamp($generation, 'completed_at'),
        );
    }

    /**
     * @param  array<string, mixed>  $generation
     */
    private static function timestamp(array $generation, string $key): ?CarbonImmutable
    {
        $value = $generation[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $isIso8601 = is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) === 1;

        if (! $isIso8601) {
            throw new UnexpectedValueException("Generation \"{$key}\" is not an ISO 8601 timestamp.");
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
