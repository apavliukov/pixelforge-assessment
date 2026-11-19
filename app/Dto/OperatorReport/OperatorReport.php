<?php

declare(strict_types=1);

namespace App\Dto\OperatorReport;

/**
 * The operator report for a period: its days, most recent first, and the period's totals.
 */
final readonly class OperatorReport
{
    public ReportFigures $totals;

    /**
     * @param  list<ReportDay>  $days
     */
    public function __construct(public array $days)
    {
        $this->totals = ReportFigures::sum(array_map(static fn (ReportDay $day): ReportFigures => $day->totals, $days));
    }

    /**
     * @return array{days: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'days' => array_map(static fn (ReportDay $day): array => $day->toArray(), $this->days),
            'totals' => $this->totals->toArray(),
        ];
    }
}
