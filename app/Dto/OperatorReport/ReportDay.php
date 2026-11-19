<?php

declare(strict_types=1);

namespace App\Dto\OperatorReport;

/**
 * One UTC day of the operator report: a row per user and the day's totals.
 */
final readonly class ReportDay
{
    public ReportFigures $totals;

    /**
     * @param  string  $date  the UTC day, `Y-m-d`
     * @param  list<ReportRow>  $rows
     */
    public function __construct(
        public string $date,
        public array $rows,
    ) {
        $this->totals = ReportFigures::sum(array_map(static fn (ReportRow $row): ReportFigures => $row->figures, $rows));
    }

    /**
     * @return array{date: string, rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'rows' => array_map(static fn (ReportRow $row): array => $row->toArray(), $this->rows),
            'totals' => $this->totals->toArray(),
        ];
    }
}
