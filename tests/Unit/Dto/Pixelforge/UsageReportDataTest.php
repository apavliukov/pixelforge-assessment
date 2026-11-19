<?php

declare(strict_types=1);

use App\Dto\Pixelforge\UsageReportData;

it('refuses a response that is not a usage report', function (array $payload): void {
    UsageReportData::fromArray($payload);
})->throws(UnexpectedValueException::class)->with([
    'no days' => [['total' => ['generations' => 0, 'cost_usd' => '0.000000000']]],
    'no total' => [['data' => []]],
    'a day without a date' => [['data' => [['generations' => 1, 'cost_usd' => '0.100000000']], 'total' => ['generations' => 1, 'cost_usd' => '0.100000000']]],
    'a cost that is a number' => [['data' => [['date' => '2026-11-01', 'generations' => 1, 'cost_usd' => 0.1]], 'total' => ['generations' => 1, 'cost_usd' => '0.100000000']]],
    'a cost with ten decimals' => [['data' => [], 'total' => ['generations' => 1, 'cost_usd' => '0.1000000001']]],
]);
