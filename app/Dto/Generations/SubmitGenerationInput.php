<?php

declare(strict_types=1);

namespace App\Dto\Generations;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\User;

final readonly class SubmitGenerationInput
{
    /**
     * @param  User  $user  who submits and pays
     * @param  string  $prompt  already validated: 1 to 4,000 characters
     * @param  ImageSize  $size  the requested image size
     * @param  ImageQuality  $quality  the requested quality
     * @param  list<string>  $referenceImages  already validated: at most 4 http(s) URLs
     */
    public function __construct(
        public User $user,
        public string $prompt,
        public ImageSize $size,
        public ImageQuality $quality,
        public array $referenceImages,
    ) {}
}
