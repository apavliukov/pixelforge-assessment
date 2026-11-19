<?php

declare(strict_types=1);

namespace App\Http\Requests\Generations;

use App\Concerns\GenerationValidationRules;
use App\Dto\Generations\SubmitGenerationInput;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class StoreGenerationRequest extends FormRequest
{
    use GenerationValidationRules;

    /**
     * @return array<string, array<int, Enum|string>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', ...$this->promptRules()],
            ...$this->imageOptionRules(),
            'reference_images' => ['nullable', 'array', 'list', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:'.self::MAX_REFERENCE_IMAGE_URL_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reference_images' => 'reference images',
            'reference_images.*' => 'reference image',
        ];
    }

    public function toInput(): SubmitGenerationInput
    {
        return new SubmitGenerationInput(
            user: $this->user(),
            prompt: $this->validated('prompt'),
            size: $this->enum('size', ImageSize::class),
            quality: $this->enum('quality', ImageQuality::class),
            referenceImages: $this->validated('reference_images') ?? [],
        );
    }
}
