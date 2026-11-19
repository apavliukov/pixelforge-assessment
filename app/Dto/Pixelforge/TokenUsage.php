<?php

declare(strict_types=1);

namespace App\Dto\Pixelforge;

use UnexpectedValueException;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /**
     * @param  array<string, mixed>  $usage  the `usage` object of a succeeded generation
     */
    public static function fromArray(array $usage): self
    {
        return new self(
            inputText: self::count($usage, 'input_text_tokens'),
            inputImage: self::count($usage, 'input_image_tokens'),
            output: self::count($usage, 'output_tokens'),
            reasoning: self::count($usage, 'reasoning_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private static function count(array $usage, string $key): int
    {
        $count = $usage[$key] ?? null;

        if (! is_int($count) || $count < 0) {
            throw new UnexpectedValueException("Usage \"{$key}\" is missing or not a token count.");
        }

        return $count;
    }
}
