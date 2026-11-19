<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\FailureCode;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as its user sees it. What the operator pays, the markup and the provider's own
 * error message are left out on purpose.
 *
 * @mixin Generation
 */
final class GenerationResource extends JsonResource
{
    private const string NOT_CHARGED = 'You were not charged.';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasImage = $this->status === GenerationStatus::Succeeded && $this->image_url !== null;
        $imageExpired = $hasImage && $this->image_url_expires_at?->isPast() === true;

        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_image_count' => count($this->reference_images),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_final' => $this->status->isFinal(),
            'failure_reason' => $this->failureReason(),
            'estimate' => [
                'min' => $this->estimate_min->toDisplay(),
                'max' => $this->estimate_max->toDisplay(),
            ],
            'charge' => $this->user_charge?->toDisplay(),
            'image_url' => $hasImage && ! $imageExpired ? $this->image_url : null,
            'image_expired' => $imageExpired,
            'submitted_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * Why a failed generation failed, in the user's terms. The stored code is one of ours or the
     * provider's own, which is an open set, so anything unknown gets the general reason.
     */
    private function failureReason(): ?string
    {
        if ($this->status !== GenerationStatus::Failed) {
            return null;
        }

        $reason = match ($this->error_code) {
            FailureCode::ContentPolicyViolation->value => 'Your prompt was rejected by the content policy. Change it and try again.',
            FailureCode::ProviderUnavailable->value => 'The image service was overloaded. Try again in a few minutes.',
            FailureCode::DailyLimitExceeded->value => "Today's generation limit has been reached. Try again tomorrow.",
            FailureCode::WaitTimeout->value => 'The image service was busy for too long. Try again.',
            'generation_failed' => 'The image could not be generated for this prompt. Try again or change the prompt.',
            default => 'Something went wrong on our side.',
        };

        return $reason.' '.self::NOT_CHARGED;
    }
}
