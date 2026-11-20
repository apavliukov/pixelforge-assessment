<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\Generations\SubmitGenerationInput;
use App\Enums\GenerationStatus;
use App\Exceptions\PricingUnavailableException;
use App\Exceptions\TooManyUnfinishedGenerationsException;
use App\Jobs\SendGenerationJob;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a generation with the price range and markup the user agreed to, and queues it to be
 * sent to the provider. The row and the queued job are written in one transaction: the queue
 * lives in the same database, so a generation never exists without the job that sends it.
 */
final readonly class SubmitGeneration
{
    /**
     * The provider's 3 slots and daily quota are shared by everyone; without a per-user limit one
     * user could take them all.
     */
    public const int MAX_UNFINISHED_PER_USER = 3;

    public function __construct(private EstimateGeneration $estimateGeneration) {}

    /**
     * @throws PricingUnavailableException when no recent pricing can be obtained
     * @throws TooManyUnfinishedGenerationsException when the user already has the maximum unfinished
     */
    public function __invoke(SubmitGenerationInput $input): Generation
    {
        $priceMultiplierBasisPoints = (int) config('pricing.price_multiplier_basis_points');

        $estimate = ($this->estimateGeneration)(
            prompt: $input->prompt,
            size: $input->size,
            quality: $input->quality,
            referenceImageCount: count($input->referenceImages),
        );

        return DB::transaction(function () use ($input, $estimate, $priceMultiplierBasisPoints): Generation {
            User::query()->whereKey($input->user->id)->lockForUpdate()->first();

            $unfinishedCount = $input->user->generations()->unfinished()->count();

            if ($unfinishedCount >= self::MAX_UNFINISHED_PER_USER) {
                throw new TooManyUnfinishedGenerationsException("User {$input->user->id} already has {$unfinishedCount} unfinished generations.");
            }

            $generation = $input->user->generations()->create([
                'prompt' => $input->prompt,
                'size' => $input->size,
                'quality' => $input->quality,
                'reference_images' => $input->referenceImages,
                'status' => GenerationStatus::Waiting,
                'price_multiplier_basis_points' => $priceMultiplierBasisPoints,
                'estimate_min' => $estimate->min,
                'estimate_max' => $estimate->max,
            ]);

            dispatch(new SendGenerationJob($generation));

            return $generation;
        });
    }
}
