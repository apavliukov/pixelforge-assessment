<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\Pixelforge\GenerationData;
use App\Dto\Pixelforge\TokenRates;
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
            $generation->rates ??= $state->rates;
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
     * The cost uses the rates recorded on the generation when the provider first reported it,
     * not the ones in this report: the provider fixes rates when it accepts a generation.
     */
    private function recordSuccess(Generation $generation, GenerationData $state): void
    {
        $usage = $state->usage ?? throw new LogicException('GenerationData guarantees usage on a succeeded generation.');
        $providerCost = $this->calculator->providerCost($usage, TokenRates::fromArray($generation->rates ?? $state->rates));

        $generation->markSucceeded(
            usage: $usage,
            providerCost: $providerCost,
            userCharge: $this->calculator->userCharge($providerCost, $generation->price_multiplier_basis_points),
            state: $state,
        );
    }

    private function contradictsFinalStatus(Generation $generation, GenerationData $state): bool
    {
        return $generation->status->isFinal()
            && $state->status->isFinal()
            && $generation->status !== $state->status;
    }
}
