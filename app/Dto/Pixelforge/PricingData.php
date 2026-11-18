<?php

declare(strict_types=1);

namespace App\Dto\Pixelforge;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Pricing\Money;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * The response of `GET /v1/pricing`: current rates and the token counts used for estimates.
 */
final readonly class PricingData
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  by quality, then size
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  by quality
     * @param  array<string, mixed>  $payload  the validated response, as received
     */
    private function __construct(
        public TokenRates $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
        public array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $tokens = $payload['tokens'] ?? null;

        if (! is_array($tokens)) {
            throw new UnexpectedValueException('Pricing has no "tokens" object.');
        }

        return new self(
            rates: TokenRates::fromArray(is_array($payload['rates'] ?? null) ? $payload['rates'] : []),
            charactersPerTextToken: self::positiveInteger($tokens, 'characters_per_text_token'),
            textTokenVarianceBasisPoints: self::varianceInBasisPoints($tokens['text_token_variance'] ?? null),
            tokensPerReferenceImage: self::positiveInteger($tokens, 'per_reference_image'),
            outputTokens: self::outputTable($tokens['output'] ?? null),
            reasoningTokens: self::reasoningTable($tokens['reasoning'] ?? null),
            payload: $payload,
        );
    }

    public function outputTokens(ImageQuality $quality, ImageSize $size): int
    {
        return $this->outputTokens[$quality->value][$size->value]
            ?? throw new UnexpectedValueException("Pricing has no output token count for {$quality->value} quality at {$size->value}.");
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningTokens(ImageQuality $quality): array
    {
        return $this->reasoningTokens[$quality->value]
            ?? throw new UnexpectedValueException("Pricing has no reasoning token range for {$quality->value} quality.");
    }

    /**
     * @param  array<array-key, mixed>  $tokens
     */
    private static function positiveInteger(array $tokens, string $key): int
    {
        $value = $tokens[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new UnexpectedValueException("Pricing \"{$key}\" is missing or not a positive integer.");
        }

        return $value;
    }

    /**
     * The variance arrives as a JSON number such as 0.15. Its shortest decimal form is read with
     * the money parser, so the estimate arithmetic stays in integers.
     */
    private static function varianceInBasisPoints(mixed $variance): int
    {
        $isNumber = is_int($variance) || is_float($variance);

        try {
            $fraction = Money::fromDecimalString($isNumber ? (string) $variance : '')->nano;
        } catch (InvalidArgumentException) {
            $fraction = null;
        }

        if ($fraction === null || $fraction >= 1_000_000_000 || $fraction % 100_000 !== 0) {
            throw new UnexpectedValueException('Pricing "text_token_variance" is missing or not a fraction with at most four decimals.');
        }

        return intdiv($fraction, 100_000);
    }

    /**
     * @return array<string, array<string, int>>
     */
    private static function outputTable(mixed $table): array
    {
        if (! is_array($table) || $table === []) {
            throw new UnexpectedValueException('Pricing has no "output" token table.');
        }

        foreach ($table as $quality => $sizes) {
            if (! is_array($sizes) || ! array_all($sizes, static fn (mixed $count): bool => is_int($count) && $count >= 0)) {
                throw new UnexpectedValueException("Pricing output tokens for \"{$quality}\" are not a table of counts.");
            }
        }

        return $table;
    }

    /**
     * @return array<string, array{min: int, max: int}>
     */
    private static function reasoningTable(mixed $table): array
    {
        if (! is_array($table) || $table === []) {
            throw new UnexpectedValueException('Pricing has no "reasoning" token ranges.');
        }

        foreach ($table as $quality => $range) {
            $isRange = is_int($range['min'] ?? null)
                && is_int($range['max'] ?? null)
                && $range['min'] >= 0
                && $range['min'] <= $range['max'];

            if (! $isRange) {
                throw new UnexpectedValueException("Pricing reasoning tokens for \"{$quality}\" are not a min–max range.");
            }
        }

        return $table;
    }
}
