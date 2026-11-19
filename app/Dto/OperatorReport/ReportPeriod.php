<?php

declare(strict_types=1);

namespace App\Dto\OperatorReport;

use Carbon\CarbonImmutable;

/**
 * The UTC days a report covers, both ends included.
 */
final readonly class ReportPeriod
{
    public const int DEFAULT_DAYS = 30;

    /** The longest period the provider's usage report accepts. */
    public const int MAX_DAYS = 92;

    private const string DATE_FORMAT = 'Y-m-d';

    /**
     * @param  CarbonImmutable  $start  midnight UTC of the first day
     * @param  CarbonImmutable  $end  midnight UTC of the last day
     */
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /**
     * A missing end is today in UTC; a missing start makes the period the default length.
     */
    public static function fromDates(?string $startDate, ?string $endDate): self
    {
        $end = $endDate === null
            ? CarbonImmutable::today('UTC')
            : CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $endDate, 'UTC');

        $start = $startDate === null
            ? $end->subDays(self::DEFAULT_DAYS - 1)
            : CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $startDate, 'UTC');

        return new self($start, $end);
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    public function endsBeforeItStarts(): bool
    {
        return $this->end->lessThan($this->start);
    }

    public function startDate(): string
    {
        return $this->start->format(self::DATE_FORMAT);
    }

    public function endDate(): string
    {
        return $this->end->format(self::DATE_FORMAT);
    }

    /**
     * The first moment after the period, for a half-open comparison of timestamps.
     */
    public function endExclusive(): CarbonImmutable
    {
        return $this->end->addDay();
    }

    /**
     * The period as the report's address carries it.
     *
     * @return array{start_date: string, end_date: string}
     */
    public function toQuery(): array
    {
        return ['start_date' => $this->startDate(), 'end_date' => $this->endDate()];
    }

    /**
     * @return array{start: string, end: string}
     */
    public function toArray(): array
    {
        return ['start' => $this->startDate(), 'end' => $this->endDate()];
    }
}
