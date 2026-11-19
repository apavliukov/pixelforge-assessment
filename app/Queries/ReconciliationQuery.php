<?php

declare(strict_types=1);

namespace App\Queries;

use App\Dto\OperatorReport\CostComparison;
use App\Dto\OperatorReport\OperatorReport;
use App\Dto\OperatorReport\Reconciliation;
use App\Dto\OperatorReport\ReportFigures;
use App\Dto\OperatorReport\ReportPeriod;
use App\Http\Clients\PixelforgeClient;
use App\Pricing\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

/**
 * Compares the provider costs in an operator report with what the provider reports it billed.
 */
final readonly class ReconciliationQuery
{
    public function __construct(private PixelforgeClient $pixelforge) {}

    /**
     * Every day either side has generations for is compared, so a day only the provider knows
     * shows up as a difference instead of being missed.
     *
     * @throws ConnectionException when Pixelforge cannot be reached
     * @throws RequestException when Pixelforge answers with an error status
     * @throws UnexpectedValueException when the response is not a valid usage report
     */
    public function get(OperatorReport $report, ReportPeriod $period): Reconciliation
    {
        $usage = $this->pixelforge->usageReport($period);

        $billed = array_filter($usage->days, static fn (array $day): bool => $day['generations'] > 0 || ! $day['cost']->isZero());
        $recorded = array_column($report->days, 'totals', 'date');
        $days = [];

        foreach (array_keys($billed + $recorded) as $date) {
            $days[$date] = new CostComparison(
                ($recorded[$date] ?? ReportFigures::zero())->providerCost,
                ($recorded[$date] ?? ReportFigures::zero())->generations,
                $billed[$date]['cost'] ?? Money::fromNano(0),
                $billed[$date]['generations'] ?? 0,
            );
        }

        krsort($days);

        return new Reconciliation($days, new CostComparison(
            $report->totals->providerCost,
            $report->totals->generations,
            $usage->total['cost'],
            $usage->total['generations'],
        ));
    }
}
