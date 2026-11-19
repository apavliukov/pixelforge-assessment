<?php

declare(strict_types=1);

namespace App\Dto\OperatorReport;

/**
 * The reconciliation of a period: every day either side has something for, and the whole period.
 */
final readonly class Reconciliation
{
    /**
     * @param  array<string, CostComparison>  $days  by UTC day, `Y-m-d`
     */
    public function __construct(
        public array $days,
        public CostComparison $total,
    ) {}

    /**
     * @return array{days: array<string, array<string, mixed>>, total: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'days' => array_map(static fn (CostComparison $day): array => $day->toArray(), $this->days),
            'total' => $this->total->toArray(),
        ];
    }
}
