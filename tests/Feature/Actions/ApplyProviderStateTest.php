<?php

declare(strict_types=1);

use App\Actions\ApplyProviderState;
use App\Dto\Pixelforge\GenerationData;
use App\Enums\FailureCode;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\Money;
use Database\Factories\GenerationFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function applyState(GenerationData $state): ?Generation
{
    return resolve(ApplyProviderState::class)($state);
}

describe('every channel', function (): void {
    it('finds a generation by local identifier when the state arrives before the create response', function (): void {
        $generation = Generation::factory()->waiting()->create();

        applyState(providerStateFor($generation, 'processing', ['id' => 'gen_early']));

        $generation->refresh();
        expect($generation->provider_id)->toBe('gen_early')
            ->and($generation->status)->toBe(GenerationStatus::Processing)
            ->and($generation->rates)->toEqual(GenerationFactory::RATES)
            ->and($generation->provider_synced_at)->not->toBeNull();
    });

    it('keeps the status when the create response arrives late', function (): void {
        $generation = Generation::factory()->processing()->create();

        applyState(providerStateFor($generation, 'queued'));

        expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
    });

    it('changes nothing and logs when no generation matches', function (array $metadata): void {
        Log::spy();
        Generation::factory()->processing()->create();

        $applied = applyState(GenerationData::fromArray(providerGeneration('succeeded', [
            'id' => 'gen_unknown',
            'metadata' => $metadata,
        ])));

        expect($applied)->toBeNull()
            ->and(Generation::query()->where('status', GenerationStatus::Succeeded)->exists())->toBeFalse();
        Log::shouldHaveReceived('warning')->once();
    })->with([
        'unknown local identifier' => [['local_id' => (string) Str::uuid7()]],
        'local identifier that is not a UUID' => [['local_id' => 'order-1042']],
        'no metadata' => [[]],
    ]);

    it('ignores a state whose local identifier belongs to a different provider generation', function (): void {
        Log::spy();
        $generation = Generation::factory()->processing()->create();

        $applied = applyState(providerStateFor($generation, 'succeeded', ['id' => 'gen_other']));

        expect($applied)->toBeNull()
            ->and($generation->refresh()->status)->toBe(GenerationStatus::Processing);
        Log::shouldHaveReceived('warning')->once();
    });
});

describe('status order', function (): void {
    it('ignores a late processing after succeeded', function (): void {
        $generation = Generation::factory()->succeeded()->create();

        applyState(providerStateFor($generation, 'processing'));

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->output_tokens)->toBe(6_450)
            ->and($generation->provider_cost)->toEqual(Money::fromNano(305_510_000))
            ->and($generation->user_charge)->toEqual(Money::fromNano(381_887_500));
    });

    it('moves straight from queued to succeeded', function (): void {
        $generation = Generation::factory()->accepted()->create();

        applyState(providerStateFor($generation, 'succeeded'));

        expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
    });

    it('keeps the first final status the provider reported and logs a contradicting one', function (): void {
        Log::spy();
        $generation = Generation::factory()->accepted()->create();
        applyState(providerStateFor($generation, 'failed'));

        applyState(providerStateFor($generation, 'succeeded'));

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->user_charge)->toEqual(Money::fromNano(0));
        Log::shouldHaveReceived('warning')->once();
    });
});

describe('money', function (): void {
    it('records the documented succeeded generation', function (): void {
        $generation = Generation::factory()->processing()->create(['price_multiplier_basis_points' => 12_500]);

        applyState(providerStateFor($generation, 'succeeded'));

        $generation->refresh();
        expect([$generation->input_text_tokens, $generation->input_image_tokens, $generation->output_tokens, $generation->reasoning_tokens])
            ->toBe([12, 765, 6_450, 3_184])
            ->and($generation->provider_cost)->toEqual(Money::fromNano(305_510_000))
            ->and($generation->user_charge)->toEqual(Money::fromNano(381_887_500))
            ->and($generation->image_url)->toStartWith('https://pixelforge.promptedit.dev/images/')
            ->and($generation->image_url_expires_at?->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z')
            ->and($generation->completed_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z');
    });

    it('records the money once even if a later success reports other usage', function (): void {
        $generation = Generation::factory()->processing()->create();
        applyState(providerStateFor($generation, 'succeeded'));

        applyState(providerStateFor($generation, 'succeeded', [
            'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 1],
        ]));

        $generation->refresh();
        expect($generation->provider_cost)->toEqual(Money::fromNano(305_510_000))
            ->and($generation->output_tokens)->toBe(6_450);
    });

    it('uses the rates on the generation, not the published ones', function (): void {
        $pricing = publishedPricing();
        $pricing['rates']['output_tokens'] = '50.00';
        Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response($pricing)]);
        $generation = Generation::factory()->processing()->create();

        applyState(providerStateFor($generation, 'succeeded'));

        expect($generation->refresh()->provider_cost)->toEqual(Money::fromNano(305_510_000));
    });

    it('records the full charge when it exceeds the shown maximum', function (): void {
        $generation = Generation::factory()->processing()->create(['estimate_max' => Money::fromNano(300_000_000)]);

        applyState(providerStateFor($generation, 'succeeded'));

        $generation->refresh();
        expect($generation->user_charge)->toEqual(Money::fromNano(381_887_500))
            ->and($generation->estimate_max)->toEqual(Money::fromNano(300_000_000));
    });

    it('charges nothing for a failed generation', function (): void {
        $generation = Generation::factory()->processing()->create();

        applyState(providerStateFor($generation, 'failed'));

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe('generation_failed')
            ->and($generation->error_message)->toBe('The model could not produce an image for this prompt.')
            ->and($generation->provider_cost)->toEqual(Money::fromNano(0))
            ->and($generation->user_charge)->toEqual(Money::fromNano(0))
            ->and($generation->completed_at)->not->toBeNull();
    });
});

describe('local failures', function (): void {
    it('gives way to a success the provider reports', function (): void {
        $generation = Generation::factory()->failedLocally(FailureCode::ProviderUnavailable)->create();

        applyState(providerStateFor($generation, 'succeeded'));

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->provider_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
            ->and($generation->output_tokens)->toBe(6_450)
            ->and($generation->user_charge)->toEqual(Money::fromNano(381_887_500))
            ->and($generation->error_code)->toBeNull();
    });

    it('gives way to a generation the provider is still running', function (): void {
        $generation = Generation::factory()->failedLocally(FailureCode::WaitTimeout)->create();

        applyState(providerStateFor($generation, 'processing'));

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Processing)
            ->and($generation->error_code)->toBeNull()
            ->and($generation->provider_cost)->toBeNull()
            ->and($generation->completed_at)->toBeNull();
    });
});
