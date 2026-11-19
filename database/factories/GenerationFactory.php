<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FailureCode;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
final class GenerationFactory extends Factory
{
    /**
     * The documented rates per 1M tokens.
     */
    public const array RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * A waiting generation: submitted, not yet accepted by the provider.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => fake()->sentence(),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Low,
            'reference_images' => [],
            'status' => GenerationStatus::Waiting,
            'price_multiplier_basis_points' => 12_500,
            'estimate_min' => Money::fromNano(14_006_250),
            'estimate_max' => Money::fromNano(21_831_250),
        ];
    }

    public function waiting(): self
    {
        return $this;
    }

    /**
     * Accepted by the provider and queued there.
     */
    public function accepted(): self
    {
        return $this->state(fn (): array => [
            'provider_id' => 'gen_'.Str::random(24),
            'status' => GenerationStatus::Queued,
            'rates' => self::RATES,
            'provider_synced_at' => now(),
        ]);
    }

    public function processing(): self
    {
        return $this->accepted()->state(fn (): array => [
            'status' => GenerationStatus::Processing,
        ]);
    }

    /**
     * The documented succeeded generation, charged at a 25% markup.
     */
    public function succeeded(): self
    {
        return $this->accepted()->state(fn (): array => [
            'status' => GenerationStatus::Succeeded,
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6_450,
            'reasoning_tokens' => 3_184,
            'provider_cost' => Money::fromNano(305_510_000),
            'user_charge' => Money::fromNano(381_887_500),
            'image_url' => 'https://pixelforge.promptedit.dev/images/'.Str::random(24).'.png',
            'image_url_expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Failed by this app before the provider accepted it.
     */
    public function failedLocally(FailureCode $code = FailureCode::ProviderUnavailable): self
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Failed,
            'error_code' => $code->value,
            'provider_cost' => Money::fromNano(0),
            'user_charge' => Money::fromNano(0),
            'completed_at' => now(),
        ]);
    }
}
