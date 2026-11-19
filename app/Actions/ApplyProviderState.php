<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\Pixelforge\GenerationData;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\PriceCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

/**
 * The single place where what the provider reports about a generation is recorded: the create
 * response, webhook events and polling all come through here, in any order and any number of
 * times. Status only moves forward, and money is recorded once, on the move to succeeded.
 */
final readonly class ApplyProviderState
{
    public function __construct(private PriceCalculator $calculator) {}

    /**
     * @return Generation|null the updated generation, or null when no generation matches
     */
    public function __invoke(GenerationData $state): ?Generation
    {
        return DB::transaction(function () use ($state): ?Generation {
            $generation = $this->findLocked($state);

            if (! $generation instanceof Generation) {
                return null;
            }

            $isLocalFailure = $generation->isLocalFailure();

            $generation->provider_id ??= $state->id;
            $generation->rates ??= $state->ratesPayload;
            $generation->provider_synced_at = now();

            if ($isLocalFailure) {
                $generation->clearFailure();
            }

            if ($isLocalFailure || $state->status->isAheadOf($generation->status)) {
                $this->moveTo($generation, $state);
            } elseif ($this->contradictsFinalStatus($generation, $state)) {
                Log::warning('Pixelforge reported a second, different final status; the first one is kept.', [
                    'generation' => $generation->id,
                    'kept' => $generation->status->value,
                    'reported' => $state->status->value,
                ]);
            }

            $generation->save();

            return $generation;
        });
    }

    private function findLocked(GenerationData $state): ?Generation
    {
        $generation = Generation::query()
            ->where('provider_id', $state->id)
            ->lockForUpdate()
            ->first();

        if ($generation !== null) {
            return $generation;
        }

        $generation = $state->localId !== null && Str::isUuid($state->localId)
            ? Generation::query()
                ->whereKey($state->localId)
                ->lockForUpdate()
                ->first()
            : null;

        if ($generation === null) {
            Log::warning('Pixelforge reported a generation this app does not know.', [
                'provider_id' => $state->id,
                'local_id' => $state->localId,
            ]);

            return null;
        }

        if ($generation->provider_id !== null) {
            Log::warning('Pixelforge reported a generation whose local identifier belongs to another provider generation.', [
                'generation' => $generation->id,
                'known_provider_id' => $generation->provider_id,
                'reported_provider_id' => $state->id,
            ]);

            return null;
        }

        return $generation;
    }

    private function moveTo(Generation $generation, GenerationData $state): void
    {
        $generation->status = $state->status;

        if ($state->status === GenerationStatus::Succeeded) {
            $this->recordSuccess($generation, $state);
        }

        if ($state->status === GenerationStatus::Failed) {
            $generation->markFailed((string) $state->errorCode, $state->errorMessage, $state->completedAt);
        }
    }

    /**
     * The rates are the ones the generation was accepted at, which the provider repeats in every
     * report.
     */
    private function recordSuccess(Generation $generation, GenerationData $state): void
    {
        $usage = $state->usage ?? throw new LogicException('GenerationData guarantees usage on a succeeded generation.');

        $providerCost = $this->calculator->providerCost($usage, $state->rates);

        $generation->input_text_tokens = $usage->inputText;
        $generation->input_image_tokens = $usage->inputImage;
        $generation->output_tokens = $usage->output;
        $generation->reasoning_tokens = $usage->reasoning;
        $generation->provider_cost = $providerCost;
        $generation->user_charge = $this->calculator->userCharge($providerCost, $generation->price_multiplier_basis_points);
        $generation->image_url = $state->imageUrl;
        $generation->image_url_expires_at = $state->imageUrlExpiresAt;
        $generation->completed_at = $state->completedAt ?? now();
    }

    private function contradictsFinalStatus(Generation $generation, GenerationData $state): bool
    {
        return $generation->status->isFinal()
            && $state->status->isFinal()
            && $generation->status !== $state->status;
    }
}
