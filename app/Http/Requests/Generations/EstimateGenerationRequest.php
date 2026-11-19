<?php

declare(strict_types=1);

namespace App\Http\Requests\Generations;

use App\Concerns\GenerationValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * A generation still being typed: the prompt may be empty, and only the number of reference
 * images matters, so a half-typed URL does not hide the price.
 */
final class EstimateGenerationRequest extends FormRequest
{
    use GenerationValidationRules;

    /**
     * @return array<string, array<int, Enum|string>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['nullable', ...$this->promptRules()],
            ...$this->imageOptionRules(),
            'reference_image_count' => ['required', 'integer', 'between:0,'.self::MAX_REFERENCE_IMAGES],
        ];
    }
}
