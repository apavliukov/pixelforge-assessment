<?php

declare(strict_types=1);

namespace App\Dto\Pixelforge;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}
}
