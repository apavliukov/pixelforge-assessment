<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

trait GenerationValidationRules
{
    public const int MAX_PROMPT_LENGTH = 4_000;

    public const int MAX_REFERENCE_IMAGES = 4;

    public const int MAX_REFERENCE_IMAGE_URL_LENGTH = 2_048;

    /**
     * The prompt rules without presence: submitting requires a prompt, estimating does not.
     * `max` counts Unicode characters, as the estimate does.
     *
     * @return array<int, string>
     */
    protected function promptRules(): array
    {
        return ['string', 'max:'.self::MAX_PROMPT_LENGTH];
    }

    /**
     * @return array<string, array<int, Enum|string>>
     */
    protected function imageOptionRules(): array
    {
        return [
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
        ];
    }
}
