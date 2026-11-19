<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use App\Pricing\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * A user listed as an operator, next to whoever is listed already.
 */
function operator(): User
{
    $operator = User::factory()->create();
    config(['app.operator_user_ids' => [...config('app.operator_user_ids'), $operator->id]]);

    return $operator;
}

/**
 * Opens the report as an operator. Without dates the app redirects to the default period, and
 * the redirect is followed.
 *
 * @param  array<string, string>  $dates
 */
function openReport(array $dates = []): TestResponse
{
    return test()->actingAs(operator())->followingRedirects()->get(route('operatorReport.show', $dates));
}

describe('access', function (): void {
    it('shows the report to an operator', function (): void {
        openReport()->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('operator/Report'));
    });

    it('refuses a user who is not an operator', function (): void {
        operator();

        $this->actingAs(User::factory()->create())->get(route('operatorReport.show'))->assertForbidden();
    });

    it('sends a guest to sign in', function (): void {
        $this->get(route('operatorReport.show'))->assertRedirect(route('login'));
    });

    it('refuses everyone when no operators are configured', function (): void {
        $this->actingAs(User::factory()->create())->get(route('operatorReport.show'))->assertForbidden();
    });

    it('does not make an operator of a user who takes an operator\'s email', function (): void {
        $operator = operator();
        $operator->forceFill(['email' => 'moved@example.com'])->save();
        $impostor = User::factory()->create(['email' => 'operator@example.com']);

        $this->actingAs($impostor)->get(route('operatorReport.show'))->assertForbidden();
        $this->actingAs($operator)->followingRedirects()->get(route('operatorReport.show'))->assertOk();
    });

    it('tells every page whether the user is an operator', function (): void {
        Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(publishedPricing())]);

        $this->actingAs(operator())->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('auth.isOperator', true));

        $this->actingAs(User::factory()->create())->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('auth.isOperator', false));
    });
});

describe('period', function (): void {
    it('covers the 30 days ending today in UTC by default', function (string $now, array $period): void {
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));

        expect(openReport()->inertiaProps('period'))->toBe($period);
    })->with([
        'late in the UTC day' => ['2026-11-19 23:30:00', ['start' => '2026-10-21', 'end' => '2026-11-19']],
        'just after midnight UTC' => ['2026-11-20 00:30:00', ['start' => '2026-10-22', 'end' => '2026-11-20']],
    ]);

    it('sends a request without dates to the address that names the period', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));

        $this->actingAs(operator())->get(route('operatorReport.show'))
            ->assertRedirect(route('operatorReport.show', ['start_date' => '2026-10-21', 'end_date' => '2026-11-19']));
    });

    it('reports on the chosen days', function (): void {
        $user = User::factory()->create();

        foreach (['2026-10-31', '2026-11-01', '2026-11-03', '2026-11-04'] as $date) {
            Generation::factory()->succeeded()->for($user)->create(['completed_at' => CarbonImmutable::parse("{$date} 12:00:00", 'UTC')]);
        }

        $response = openReport(['start_date' => '2026-11-01', 'end_date' => '2026-11-03']);

        expect($response->inertiaProps('period'))->toBe(['start' => '2026-11-01', 'end' => '2026-11-03'])
            ->and(array_column($response->inertiaProps('report.days'), 'date'))->toBe(['2026-11-03', '2026-11-01'])
            ->and($response->inertiaProps('report.totals.generations'))->toBe(2);
    });

    it('accepts a period of 92 days', function (): void {
        openReport(['start_date' => '2026-08-20', 'end_date' => '2026-11-19'])->assertOk();
    });

    it('refuses a period it cannot report on, with the error on the date that was given', function (array $dates, string $field, string $error): void {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));

        $this->actingAs(operator())->get(route('operatorReport.show', $dates))
            ->assertRedirect()
            ->assertSessionHasErrors([$field => $error]);
    })->with([
        '93 days' => [['start_date' => '2026-08-19', 'end_date' => '2026-11-19'], 'end_date', 'The period must not be longer than 92 days.'],
        'only a start date, too long ago' => [['start_date' => '2026-01-01'], 'start_date', 'The period must not be longer than 92 days.'],
        'end before start' => [['start_date' => '2026-11-03', 'end_date' => '2026-11-01'], 'end_date', 'The end date must not be before the start date.'],
        'end in the future' => [['start_date' => '2026-11-01', 'end_date' => '2026-11-20'], 'end_date', 'The period must not end in the future.'],
        'only a start date, in the future' => [['start_date' => '2026-11-25'], 'start_date', 'The start date must not be in the future.'],
        'not a date' => [['start_date' => 'yesterday'], 'start_date', 'The start date field must match the format Y-m-d.'],
        'a day that does not exist' => [['start_date' => '2026-02-31', 'end_date' => '2026-03-02'], 'start_date', 'The start date field must match the format Y-m-d.'],
    ]);

    it('leads a refused period back to the report, where the error is shown', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));

        openReport(['start_date' => '2026-01-01'])
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('operator/Report')
                ->where('period', ['start' => '2026-10-21', 'end' => '2026-11-19'])
                ->where('errors.start_date', 'The period must not be longer than 92 days.'));
    });
});

it('says how many generations are unfinished across all users', function (): void {
    Generation::factory()->processing()->for(User::factory())->create();
    Generation::factory()->waiting()->for(User::factory())->create();
    Generation::factory()->succeeded()->for(User::factory())->create();

    expect(openReport()->inertiaProps('unfinishedCount'))->toBe(2);
});

describe('charges above the estimate', function (): void {
    it('lists a generation charged above its estimate with the excess', function (): void {
        $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
        $generation = Generation::factory()->succeeded()->for($user)->create([
            'estimate_max' => Money::fromNano(86_250_000),
            'provider_cost' => Money::fromNano(60_000_000),
            'user_charge' => Money::fromNano(90_000_000),
            'completed_at' => CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'),
        ]);

        expect(openReport(['start_date' => '2026-11-01', 'end_date' => '2026-11-03'])->inertiaProps('aboveEstimate'))->toBe([[
            'id' => $generation->id,
            'user' => ['name' => 'Ada', 'email' => 'ada@example.com'],
            'completedAt' => '2026-11-02T10:00:00Z',
            'estimateMax' => '0.086250000',
            'userCharge' => '0.090000000',
            'excess' => '0.003750000',
        ]]);
    });

    it('lists nothing when every charge is within its estimate', function (): void {
        Generation::factory()->succeeded()->for(User::factory())->create([
            'estimate_max' => Money::fromNano(86_250_000),
            'provider_cost' => Money::fromNano(60_000_000),
            'user_charge' => Money::fromNano(86_250_000),
        ]);

        expect(openReport()->inertiaProps('aboveEstimate'))->toBe([]);
    });
});

describe('reconciliation', function (): void {
    it('shows our figures without asking the provider', function (): void {
        Http::fake();

        openReport()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('report')
            ->missing('reconciliation'));

        Http::assertNothingSent();
    });

    it('loads the reconciliation afterwards, for the same period', function (): void {
        Generation::factory()->succeeded()->for(User::factory())->create([
            'provider_cost' => Money::fromNano(169_080_000),
            'completed_at' => CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        ]);
        fakeUsageReport(['2026-11-01' => [1, '0.169080000']]);

        openReport(['start_date' => '2026-11-01', 'end_date' => '2026-11-03'])
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->loadDeferredProps(fn (AssertableInertia $reload): AssertableInertia => $reload
                    ->where('reconciliation.days.2026-11-01.matches', true)
                    ->where('reconciliation.total.provider', '0.169080000')));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-01&end_date=2026-11-03');
    });

    it('says nothing about the provider when its usage cannot be obtained', function (int $status): void {
        Http::fake(['pixelforge.promptedit.dev/v1/usage*' => Http::response(['error' => []], $status)]);

        openReport()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('report')
            ->loadDeferredProps(fn (AssertableInertia $reload): AssertableInertia => $reload->where('reconciliation', null)));
    })->with(['overloaded' => 503, 'rate limited' => 429, 'not a usage report' => 200]);
});
