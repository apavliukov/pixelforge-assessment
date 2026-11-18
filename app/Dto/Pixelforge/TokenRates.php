<?php

declare(strict_types=1);

namespace App\Dto\Pixelforge;

use App\Pricing\Money;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * The price of one token of each type. Pixelforge publishes rates per 1M tokens.
 */
final readonly class TokenRates
{
    private const int TOKENS_PER_RATE = 1_000_000;

    public function __construct(
        public Money $inputText,
        public Money $inputImage,
        public Money $output,
        public Money $reasoning,
    ) {}

    /**
     * @param  array<string, mixed>  $rates  the `rates` object of `/v1/pricing` or of a generation
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            inputText: self::perToken($rates, 'input_text_tokens'),
            inputImage: self::perToken($rates, 'input_image_tokens'),
            output: self::perToken($rates, 'output_tokens'),
            reasoning: self::perToken($rates, 'reasoning_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $rates
     */
    private static function perToken(array $rates, string $key): Money
    {
        try {
            $perMillion = Money::fromDecimalString(is_string($rates[$key] ?? null) ? $rates[$key] : '');
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException("Rate \"{$key}\" is missing or not a decimal string.", $exception->getCode(), previous: $exception);
        }

        if ($perMillion->nano % self::TOKENS_PER_RATE !== 0) {
            throw new UnexpectedValueException("Rate \"{$key}\" ({$rates[$key]}) is not a whole number of nanodollars per token.");
        }

        return Money::fromNano(intdiv($perMillion->nano, self::TOKENS_PER_RATE));
    }
}
