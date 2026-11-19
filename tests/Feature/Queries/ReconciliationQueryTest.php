<?php

declare(strict_types=1);

use App\Dto\OperatorReport\ReportPeriod;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\Money;
use App\Queries\OperatorReportQuery;
use App\Queries\ReconciliationQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * Records succeeded generations completed at noon UTC of the date, with these provider costs.
 *
 * @param  list<int>  $providerCosts  nanodollars
 */
function recordedOn(string $date, array $providerCosts): void
{
    $user = User::factory()->create();

    foreach ($providerCosts as $providerCost) {
        Generation::factory()->succeeded()->for($user)->create([
            'provider_cost' => Money::fromNano($providerCost),
            'completed_at' => CarbonImmutable::parse("{$date} 12:00:00", 'UTC'),
        ]);
    }
}

/**
 * @return array<string, mixed> the reconciliation as the browser receives it
 */
function reconcile(string $startDate, string $endDate): array
{
    $period = ReportPeriod::fromDates($startDate, $endDate);

    return resolve(ReconciliationQuery::class)->get(resolve(OperatorReportQuery::class)->get($period), $period)->toArray();
}

it('marks a day as matching when the cost and the number of generations are equal', function (): void {
    recordedOn('2026-11-01', [100_000_000, 69_080_000]);
    fakeProviderUsage(['2026-11-01' => [2, '0.169080000'], '2026-11-02' => [0, '0.000000000']]);

    $matching = [
        'ours' => '0.169080000',
        'provider' => '0.169080000',
        'generations' => ['ours' => 2, 'provider' => 2],
        'matches' => true,
        'difference' => '0.000000000',
        'providerIsHigher' => false,
    ];

    expect(reconcile('2026-11-01', '2026-11-02'))->toBe(['days' => ['2026-11-01' => $matching], 'total' => $matching]);
});

it('gives the difference when the provider billed more', function (): void {
    recordedOn('2026-11-19', [100_000_000, 100_000_000, 100_000_000, 100_000_000, 100_000_000, 100_000_000, 100_000_000, 58_660_000]);
    fakeProviderUsage(['2026-11-19' => [10, '0.788892500']]);

    $day = reconcile('2026-11-19', '2026-11-19')['days']['2026-11-19'];

    expect($day)->toMatchArray([
        'ours' => '0.758660000',
        'provider' => '0.788892500',
        'generations' => ['ours' => 8, 'provider' => 10],
        'matches' => false,
        'difference' => '0.030232500',
        'providerIsHigher' => true,
    ]);
});

it('gives the difference when we recorded more', function (): void {
    recordedOn('2026-11-01', [100_000_000, 100_000_000]);
    fakeProviderUsage(['2026-11-01' => [1, '0.100000000']]);

    expect(reconcile('2026-11-01', '2026-11-01')['days']['2026-11-01'])->toMatchArray([
        'matches' => false,
        'difference' => '0.100000000',
        'providerIsHigher' => false,
    ]);
});

it('does not match when the costs are equal and the numbers of generations differ', function (): void {
    recordedOn('2026-11-01', [100_000_000, 100_000_000]);
    fakeProviderUsage(['2026-11-01' => [1, '0.200000000']]);

    expect(reconcile('2026-11-01', '2026-11-01')['days']['2026-11-01'])->toMatchArray([
        'matches' => false,
        'difference' => '0.000000000',
    ]);
});

it('reconciles a day only the provider knows, with our cost as zero', function (): void {
    recordedOn('2026-11-02', [100_000_000]);
    fakeProviderUsage(['2026-11-01' => [1, '0.050000000'], '2026-11-02' => [1, '0.100000000']]);

    $reconciliation = reconcile('2026-11-01', '2026-11-02');

    expect(array_keys($reconciliation['days']))->toBe(['2026-11-02', '2026-11-01'])
        ->and($reconciliation['days']['2026-11-01'])->toMatchArray([
            'ours' => '0.000000000',
            'provider' => '0.050000000',
            'generations' => ['ours' => 0, 'provider' => 1],
            'matches' => false,
            'difference' => '0.050000000',
        ])
        ->and($reconciliation['total'])->toMatchArray(['ours' => '0.100000000', 'provider' => '0.150000000', 'matches' => false]);
});
