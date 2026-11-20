<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SendGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function submitGenerationForm(User $user, array $overrides = []): TestResponse
{
    return test()->actingAs($user)
        ->from(route('generations.index', ['page' => 2]))
        ->post(route('generations.store'), [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/one.png', 'http://example.com/two.png'],
            ...$overrides,
        ]);
}

it('is not available to guests', function (): void {
    $this->post(route('generations.store'), [])->assertRedirect(route('login'));
});

it('explains that pricing is unavailable and records nothing', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(status: 503)]);

    submitGenerationForm(User::factory()->create())->assertSessionHasErrors([
        'generation' => "Pricing is temporarily unavailable, so generations can't be submitted right now.",
    ]);

    expect(Generation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

describe('with published pricing', function (): void {
    beforeEach(function (): void {
        Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(publishedPricing())]);
    });

    it('records a waiting generation and returns to the first page of the history', function (): void {
        $user = User::factory()->create();

        submitGenerationForm($user)->assertSessionHasNoErrors()->assertRedirect(route('generations.index'));

        $generation = $user->generations()->sole();
        expect($generation->status)->toBe(GenerationStatus::Waiting)
            ->and($generation->prompt)->toBe(str_repeat('a', 400))
            ->and($generation->size)->toBe(ImageSize::Landscape)
            ->and($generation->quality)->toBe(ImageQuality::High)
            ->and($generation->reference_images)->toBe(['https://example.com/one.png', 'http://example.com/two.png'])
            ->and($generation->estimate_max)->toEqual(Money::fromNano(467_343_750));
        Queue::assertPushed(SendGenerationJob::class, 1);
    });

    it('records the estimate the form was shown', function (): void {
        $user = User::factory()->create();
        $shown = $this->actingAs($user)->postJson(route('generationEstimates.store'), [
            'prompt' => 'A lighthouse 🌊',
            'size' => '1024x1536',
            'quality' => 'medium',
            'reference_image_count' => 1,
        ])->json();

        submitGenerationForm($user, [
            'prompt' => 'A lighthouse 🌊',
            'size' => '1024x1536',
            'quality' => 'medium',
            'reference_images' => ['https://example.com/one.png'],
        ]);

        $generation = $user->generations()->sole();
        expect($generation->estimate_min->toDisplay())->toBe($shown['min'])
            ->and($generation->estimate_max->toDisplay())->toBe($shown['max']);
    });

    it('accepts a generation without reference images', function (array $overrides): void {
        $user = User::factory()->create();

        submitGenerationForm($user, $overrides)->assertSessionHasNoErrors();

        expect($user->generations()->sole()->reference_images)->toBe([]);
    })->with([
        'empty list' => [['reference_images' => []]],
        'missing' => [['reference_images' => null]],
    ]);

    it('accepts a prompt of 4,000 emoji', function (): void {
        submitGenerationForm(User::factory()->create(), ['prompt' => str_repeat('🎨', 4_000)])->assertSessionHasNoErrors();
    });

    it('refuses an invalid request with an error on its field and records nothing', function (array $overrides, string $field): void {
        submitGenerationForm(User::factory()->create(), $overrides)
            ->assertRedirect(route('generations.index', ['page' => 2]))
            ->assertSessionHasErrors([$field]);

        expect(Generation::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'missing prompt' => [['prompt' => ''], 'prompt'],
        'prompt of only spaces' => [['prompt' => '   '], 'prompt'],
        'prompt too long' => [['prompt' => str_repeat('a', 4_001)], 'prompt'],
        'unknown size' => [['size' => '2048x2048'], 'size'],
        'unknown quality' => [['quality' => 'ultra'], 'quality'],
        'reference image with another scheme' => [['reference_images' => ['https://example.com/one.png', 'ftp://example.com/a.png']], 'reference_images.1'],
        'reference image that is not a URL' => [['reference_images' => ['https://example.com/one.png', 'not a url']], 'reference_images.1'],
        'empty reference image' => [['reference_images' => ['']], 'reference_images.0'],
        'reference image URL too long' => [['reference_images' => ['https://example.com/'.str_repeat('a', 2_048)]], 'reference_images.0'],
        'five reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/one.png')], 'reference_images'],
    ]);

    it('explains the limit of unfinished generations and records nothing more', function (): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->count(3)->processing()->create();

        submitGenerationForm($user)->assertSessionHasErrors([
            'generation' => 'You have 3 generations in progress. Wait for one to finish.',
        ]);

        expect($user->generations()->count())->toBe(3);
        Queue::assertNothingPushed();
    });
});
