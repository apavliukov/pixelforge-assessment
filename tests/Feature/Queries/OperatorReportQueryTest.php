<?php

declare(strict_types=1);

use App\Dto\OperatorReport\ReportPeriod;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\Money;
use App\Queries\OperatorReportQuery;
use Carbon\CarbonImmutable;

/**
 * A succeeded generation of the user, completed at the given UTC moment.
 */
function completedGeneration(User $user, string $completedAt, int $providerCost = 373_875_000, int $userCharge = 467_343_750): Generation
{
    return Generation::factory()->succeeded()->for($user)->create([
        'provider_cost' => Money::fromNano($providerCost),
        'user_charge' => Money::fromNano($userCharge),
        'completed_at' => CarbonImmutable::parse($completedAt, 'UTC'),
    ]);
}

/**
 * @return array{days: list<array<string, mixed>>, totals: array<string, mixed>}
 */
function reportFor(string $startDate, string $endDate): array
{
    return resolve(OperatorReportQuery::class)->get(ReportPeriod::fromDates($startDate, $endDate))->toArray();
}

it('lists one generation with its exact user charge, provider cost and profit', function (): void {
    $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
    completedGeneration($user, '2026-11-02 10:00:00');

    $figures = ['generations' => 1, 'userCharge' => '0.467343750', 'providerCost' => '0.373875000', 'profit' => '0.093468750'];

    expect(reportFor('2026-11-01', '2026-11-03'))->toBe([
        'days' => [[
            'date' => '2026-11-02',
            'rows' => [['user' => ['name' => 'Ada', 'email' => 'ada@example.com'], ...$figures]],
            'totals' => $figures,
        ]],
        'totals' => $figures,
    ]);
});

it('gives each user a row and the day the sum of its rows', function (): void {
    $ada = User::factory()->create(['email' => 'ada@example.com']);
    $bob = User::factory()->create(['email' => 'bob@example.com']);
    completedGeneration($ada, '2026-11-02 09:00:00', providerCost: 100_000_000, userCharge: 125_000_000);
    completedGeneration($ada, '2026-11-02 21:00:00', providerCost: 200_000_000, userCharge: 250_000_000);
    completedGeneration($bob, '2026-11-02 12:00:00', providerCost: 40_000_001, userCharge: 50_000_002);

    $day = reportFor('2026-11-02', '2026-11-02')['days'][0];

    expect($day['rows'])->toHaveCount(2)
        ->and($day['rows'][0])->toMatchArray(['generations' => 2, 'userCharge' => '0.375000000', 'providerCost' => '0.300000000', 'profit' => '0.075000000'])
        ->and($day['rows'][0]['user']['email'])->toBe('ada@example.com')
        ->and($day['rows'][1])->toMatchArray(['generations' => 1, 'userCharge' => '0.050000002', 'providerCost' => '0.040000001', 'profit' => '0.010000001'])
        ->and($day['totals'])->toBe(['generations' => 3, 'userCharge' => '0.425000002', 'providerCost' => '0.340000001', 'profit' => '0.085000001']);
});

it('puts generations a second apart around midnight UTC on their own days, most recent day first', function (): void {
    $user = User::factory()->create();
    completedGeneration($user, '2026-11-02 23:59:59', providerCost: 100_000_000, userCharge: 125_000_000);
    completedGeneration($user, '2026-11-03 00:00:00', providerCost: 200_000_000, userCharge: 250_000_000);

    $report = reportFor('2026-11-01', '2026-11-04');

    expect(array_column($report['days'], 'date'))->toBe(['2026-11-03', '2026-11-02'])
        ->and($report['days'][0]['totals']['providerCost'])->toBe('0.200000000')
        ->and($report['days'][1]['totals']['providerCost'])->toBe('0.100000000')
        ->and($report['totals'])->toBe(['generations' => 2, 'userCharge' => '0.375000000', 'providerCost' => '0.300000000', 'profit' => '0.075000000']);
});

it('lists a generation under the day it was completed, not the day it was submitted', function (): void {
    $generation = completedGeneration(User::factory()->create(), '2026-11-03 00:00:20');
    $generation->forceFill(['created_at' => CarbonImmutable::parse('2026-11-02 23:59:50', 'UTC')])->save();

    expect(array_column(reportFor('2026-11-01', '2026-11-04')['days'], 'date'))->toBe(['2026-11-03']);
});

it('leaves out failed and unfinished generations', function (): void {
    $user = User::factory()->create();
    Generation::factory()->failedLocally()->for($user)->create(['completed_at' => CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC')]);
    Generation::factory()->processing()->for($user)->create();

    expect(reportFor('2026-11-01', '2026-11-30'))->toBe([
        'days' => [],
        'totals' => ['generations' => 0, 'userCharge' => '0.000000000', 'providerCost' => '0.000000000', 'profit' => '0.000000000'],
    ]);
});

it('covers both ends of the period and nothing outside it', function (): void {
    $user = User::factory()->create();

    foreach (['2026-10-31 23:59:59', '2026-11-01 00:00:00', '2026-11-03 23:59:59', '2026-11-04 00:00:00'] as $completedAt) {
        completedGeneration($user, $completedAt);
    }

    expect(array_column(reportFor('2026-11-01', '2026-11-03')['days'], 'date'))->toBe(['2026-11-03', '2026-11-01']);
});

it('finds generations charged above their estimate', function (): void {
    $user = User::factory()->create();
    $above = completedGeneration($user, '2026-11-02 10:00:00', userCharge: 90_000_000);
    $above->forceFill(['estimate_max' => Money::fromNano(86_250_000)])->save();
    $within = completedGeneration($user, '2026-11-02 11:00:00', userCharge: 86_250_000);
    $within->forceFill(['estimate_max' => Money::fromNano(86_250_000)])->save();

    $found = resolve(OperatorReportQuery::class)->chargedAboveEstimate(ReportPeriod::fromDates('2026-11-01', '2026-11-03'));

    expect($found->modelKeys())->toBe([$above->id]);
});
