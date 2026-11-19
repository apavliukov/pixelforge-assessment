<?php

declare(strict_types=1);

use App\Enums\FailureCode;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\Money;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * The generations the page lists for the user, as the browser receives them.
 *
 * @return list<array<string, mixed>>
 */
function listedGenerations(User $user, int $page = 1): array
{
    return test()->actingAs($user)
        ->get(route('generations.index', ['page' => $page]))
        ->assertOk()
        ->inertiaProps('generations.data');
}

it('sends guests to sign in', function (): void {
    $this->get(route('generations.index'))->assertRedirect(route('login'));
});

it('leads from the site root to the generation page', function (): void {
    $this->get('/')->assertRedirect(route('generations.index'));
});

it('carries no estimate when pricing is unavailable', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(status: 503)]);

    $this->actingAs(User::factory()->create())
        ->get(route('generations.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('estimate', null));
});

describe('with published pricing', function (): void {
    beforeEach(function (): void {
        Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(publishedPricing())]);
    });

    it('opens with the estimate for an empty prompt and the default options', function (): void {
        $this->actingAs(User::factory()->create())
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('generations/Index')
                ->where('defaults', ['size' => '1024x1024', 'quality' => 'medium'])
                ->where('limits', ['promptLength' => 4_000, 'referenceImages' => 4])
                ->where('estimate.min.exact', '0.058125000')
                ->where('estimate.max.exact', '0.086250000')
                ->where('unfinishedCount', 0)
                ->where('maxUnfinished', 3));
    });

    it('knows when the user has reached the limit of unfinished generations', function (): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->waiting()->create();
        Generation::factory()->for($user)->accepted()->create();
        Generation::factory()->for($user)->processing()->create();
        Generation::factory()->for($user)->succeeded()->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('unfinishedCount', 3));
    });

    it('refreshes the history without recalculating the estimate', function (): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->processing()->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->reloadOnly(['generations', 'unfinishedCount'], fn (AssertableInertia $reload): AssertableInertia => $reload
                    ->has('generations.data', 1)
                    ->where('unfinishedCount', 1)
                    ->missing('estimate')));
    });
});

describe('history', function (): void {
    beforeEach(function (): void {
        Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(publishedPricing())]);
    });

    it('lists the user\'s own generations, newest first', function (): void {
        $user = User::factory()->create();
        $oldest = Generation::factory()->for($user)->create(['created_at' => now()->subHours(2)]);
        $newest = Generation::factory()->for($user)->create(['created_at' => now()]);
        $middle = Generation::factory()->for($user)->create(['created_at' => now()->subHour()]);
        Generation::factory()->create();

        expect(array_column(listedGenerations($user), 'id'))->toBe([$newest->id, $middle->id, $oldest->id]);
    });

    it('lists 15 generations per page', function (): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->count(16)->create();

        expect(listedGenerations($user))->toHaveCount(15)
            ->and(listedGenerations($user, page: 2))->toHaveCount(1);
    });

    it('shows the prompt, the parameters, the status in plain words and the submission time', function (): void {
        $this->freezeSecond();
        $user = User::factory()->create();
        Generation::factory()->for($user)->processing()->create([
            'prompt' => 'A lighthouse at dusk',
            'reference_images' => ['https://example.com/one.png', 'https://example.com/two.png'],
        ]);

        expect(listedGenerations($user)[0])->toMatchArray([
            'prompt' => 'A lighthouse at dusk',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_image_count' => 2,
            'status' => 'processing',
            'status_label' => 'Generating',
            'is_final' => false,
            'submitted_at' => now()->toIso8601ZuluString(),
        ]);
    });

    it('shows the estimate and no charge while unfinished', function (): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->accepted()->create();

        expect(listedGenerations($user)[0])->toMatchArray([
            'estimate' => [
                'min' => ['formatted' => '$0.0141', 'exact' => '0.014006250'],
                'max' => ['formatted' => '$0.0219', 'exact' => '0.021831250'],
            ],
            'charge' => null,
            'failure_reason' => null,
        ]);
    });

    it('shows the charge next to the estimate once succeeded', function (): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create(['user_charge' => Money::fromNano(467_343_750)]);

        $listed = listedGenerations($user)[0];

        expect($listed['charge'])->toBe(['formatted' => '$0.4674', 'exact' => '0.467343750'])
            ->and($listed['estimate']['max']['formatted'])->toBe('$0.0219')
            ->and($listed['status_label'])->toBe('Done');
    });

    it('keeps the operator\'s figures and the provider\'s message out of the browser', function (): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create();
        Generation::factory()->for($user)->failedLocally()->create(['error_message' => 'Pixelforge says overloaded.']);

        $sent = json_encode(listedGenerations($user), JSON_THROW_ON_ERROR);

        expect($sent)->not->toContain('305510000', '0.305510000', '12500', 'Pixelforge says overloaded.', 'gen_', 'rates');
    });

    it('explains a failure by its code and says the user was not charged', function (string $code, string $reason): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->failedLocally()->create(['error_code' => $code]);

        expect(listedGenerations($user)[0])->toMatchArray([
            'status_label' => 'Failed',
            'charge' => ['formatted' => '$0.00', 'exact' => '0.000000000'],
            'failure_reason' => "{$reason} You were not charged.",
        ]);
    })->with([
        'content policy' => ['content_policy_violation', 'Your prompt was rejected by the content policy. Change it and try again.'],
        'provider unavailable' => ['provider_unavailable', 'The image service was overloaded. Try again in a few minutes.'],
        'daily limit' => ['daily_limit_exceeded', "Today's generation limit has been reached. Try again tomorrow."],
        'wait timeout' => ['wait_timeout', 'The image service was busy for too long. Try again.'],
        'generation failed' => ['generation_failed', 'The image could not be generated for this prompt. Try again or change the prompt.'],
        'our own defect' => [FailureCode::SubmissionRejected->value, 'Something went wrong on our side.'],
        'a code never seen before' => ['quantum_flux', 'Something went wrong on our side.'],
    ]);

    it('gives the image link only while it works', function (array $attributes, ?string $imageUrl, bool $expired): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create(['image_url' => 'https://pixelforge.promptedit.dev/images/a.png', ...$attributes]);

        expect(listedGenerations($user)[0])->toMatchArray(['image_url' => $imageUrl, 'image_expired' => $expired]);
    })->with([
        'fresh' => [fn (): array => ['image_url_expires_at' => now()->addHour()], 'https://pixelforge.promptedit.dev/images/a.png', false],
        'expired a minute ago' => [fn (): array => ['image_url_expires_at' => now()->subMinute()], null, true],
    ]);

    it('has no image for a generation that has not succeeded', function (string $state): void {
        $user = User::factory()->create();
        Generation::factory()->for($user)->{$state}()->create();

        expect(listedGenerations($user)[0])->toMatchArray(['image_url' => null, 'image_expired' => false]);
    })->with(['waiting', 'processing', 'failedLocally']);
});
