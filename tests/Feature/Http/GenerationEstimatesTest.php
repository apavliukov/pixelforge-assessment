<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function requestEstimate(array $overrides = []): TestResponse
{
    return test()->actingAs(User::factory()->create())->postJson(route('generationEstimates.store'), [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_image_count' => 2,
        ...$overrides,
    ]);
}

it('is not available to guests', function (): void {
    $this->postJson(route('generationEstimates.store'), [])->assertUnauthorized();
});

it('says pricing is unavailable instead of estimating', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(status: 503)]);

    requestEstimate()->assertServiceUnavailable()->assertExactJson([
        'message' => "Pricing is temporarily unavailable, so generations can't be submitted right now.",
    ]);
});

describe('with published pricing', function (): void {
    beforeEach(function (): void {
        Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(publishedPricing())]);
    });

    it('answers the documented example with displayed and exact amounts', function (): void {
        requestEstimate()->assertOk()->assertExactJson([
            'min' => ['formatted' => '$0.3578', 'exact' => '0.357781250'],
            'max' => ['formatted' => '$0.4674', 'exact' => '0.467343750'],
        ]);
    });

    it('estimates an empty prompt, with text contributing nothing', function (?string $prompt): void {
        $withoutText = requestEstimate(['prompt' => $prompt])->assertOk()->json();
        $withOneCharacter = requestEstimate(['prompt' => 'a'])->assertOk()->json();

        expect($withoutText['min']['exact'])->toBe('0.357250000')
            ->and($withoutText['max']['exact'])->toBe('0.466625000')
            ->and($withOneCharacter['max']['exact'])->not->toBe($withoutText['max']['exact']);
    })->with(['empty' => [''], 'missing' => [null], 'only spaces' => ['   ']]);

    it('names the field that makes an estimate impossible', function (array $overrides, string $field): void {
        requestEstimate($overrides)->assertUnprocessable()->assertJsonValidationErrors([$field]);
    })->with([
        'prompt too long' => [['prompt' => str_repeat('a', 4_001)], 'prompt'],
        'unknown size' => [['size' => '2048x2048'], 'size'],
        'unknown quality' => [['quality' => 'ultra'], 'quality'],
        'too many reference images' => [['reference_image_count' => 5], 'reference_image_count'],
    ]);

    it('accepts a prompt of 4,000 emoji', function (): void {
        requestEstimate(['prompt' => str_repeat('🎨', 4_000)])->assertOk();
    });

    it('refuses requests over the limit', function (): void {
        $user = User::factory()->create();
        $payload = ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low', 'reference_image_count' => 0];

        foreach (range(1, 120) as $attempt) {
            $this->actingAs($user)->postJson(route('generationEstimates.store'), $payload)->assertOk();
        }

        $this->actingAs($user)->postJson(route('generationEstimates.store'), $payload)->assertTooManyRequests();
    });
});
