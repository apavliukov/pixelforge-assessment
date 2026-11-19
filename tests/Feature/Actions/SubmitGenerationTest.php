<?php

declare(strict_types=1);

use App\Actions\ApplyProviderState;
use App\Actions\SubmitGeneration;
use App\Dto\Generations\SubmitGenerationInput;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PricingUnavailableException;
use App\Exceptions\TooManyUnfinishedGenerationsException;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
});

function submitFor(User $user, string $prompt = 'A lighthouse'): Generation
{
    return resolve(SubmitGeneration::class)(new SubmitGenerationInput(
        user: $user,
        prompt: $prompt,
        size: ImageSize::Landscape,
        quality: ImageQuality::High,
        referenceImages: ['https://example.com/one.png', 'https://example.com/two.png'],
    ));
}

it('refuses to submit when no pricing can be obtained', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(status: 503)]);

    expect(fn (): Generation => submitFor(User::factory()->create()))->toThrow(PricingUnavailableException::class)
        ->and(Generation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

describe('with published pricing', function (): void {
    beforeEach(function (): void {
        Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(publishedPricing())]);
    });

    it('records a waiting generation with the estimate and markup the user agreed to', function (): void {
        $user = User::factory()->create();

        $generation = submitFor($user, str_repeat('a', 400))->fresh();

        expect($generation->user_id)->toBe($user->id)
            ->and($generation->status)->toBe(GenerationStatus::Waiting)
            ->and($generation->prompt)->toBe(str_repeat('a', 400))
            ->and($generation->size)->toBe(ImageSize::Landscape)
            ->and($generation->quality)->toBe(ImageQuality::High)
            ->and($generation->reference_images)->toBe(['https://example.com/one.png', 'https://example.com/two.png'])
            ->and($generation->estimate_min)->toEqual(Money::fromNano(357_781_250))
            ->and($generation->estimate_max)->toEqual(Money::fromNano(467_343_750))
            ->and($generation->price_multiplier_basis_points)->toBe(12_500);
        Queue::assertPushedOn('submissions', SubmitGenerationJob::class, fn (SubmitGenerationJob $job): bool => $job->generation->is($generation));
        Queue::assertPushed(SubmitGenerationJob::class, 1);
    });

    it('charges the markup recorded at submission after the configured markup changes', function (): void {
        $generation = submitFor(User::factory()->create());
        config(['pricing.price_multiplier_basis_points' => 20_000]);

        resolve(ApplyProviderState::class)(providerStateFor($generation, 'succeeded'));

        expect($generation->fresh()->user_charge)->toEqual(Money::fromNano(381_887_500));
    });

    it('refuses a fourth unfinished generation', function (): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->waiting()->create();
        Generation::factory()->for($user)->accepted()->create();
        Generation::factory()->for($user)->processing()->create();

        expect(fn (): Generation => submitFor($user))->toThrow(TooManyUnfinishedGenerationsException::class)
            ->and($user->generations()->count())->toBe(3);
        Queue::assertNothingPushed();
    });

    it('does not count finished generations towards the limit', function (): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->count(2)->processing()->create();
        Generation::factory()->for($user)->count(4)->succeeded()->create();
        Generation::factory()->for($user)->failedLocally()->create();

        submitFor($user);

        expect($user->generations()->count())->toBe(8);
    });

    it('does not count other users\' generations towards the limit', function (): void {
        Generation::factory()->count(3)->processing()->create();
        $user = User::factory()->create();

        submitFor($user);

        expect($user->generations()->count())->toBe(1);
    });
});
