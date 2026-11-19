<?php

declare(strict_types=1);

namespace App\Dto\Pixelforge;

use App\Pricing\Money;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * The response of `GET /v1/usage`: what the provider billed per UTC day, and in total.
 */
final readonly class UsageReportData
{
    /**
     * @param  array<string, array{generations: int, cost: Money}>  $days  by UTC day, `Y-m-d`; days without usage are included
     * @param  array{generations: int, cost: Money}  $total
     */
    private function __construct(
        public array $days,
        public array $total,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $rows = $payload['data'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new UnexpectedValueException('Usage has no "data" list.');
        }

        $days = [];

        foreach ($rows as $row) {
            $date = is_array($row) ? ($row['date'] ?? null) : null;

            if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new UnexpectedValueException('A usage day has no date.');
            }

            $days[$date] = self::figures($row, "Usage for {$date}");
        }

        return new self($days, self::figures($payload['total'] ?? null, 'The usage total'));
    }

    /**
     * @return array{generations: int, cost: Money}
     */
    private static function figures(mixed $figures, string $subject): array
    {
        $generations = is_array($figures) ? ($figures['generations'] ?? null) : null;
        $cost = is_array($figures) ? ($figures['cost_usd'] ?? null) : null;

        if (! is_int($generations) || $generations < 0 || ! is_string($cost)) {
            throw new UnexpectedValueException("{$subject} has no generation count or cost.");
        }

        try {
            return ['generations' => $generations, 'cost' => Money::fromDecimalString($cost)];
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException("{$subject} has a cost that is not an exact amount.", $exception->getCode(), previous: $exception);
        }
    }
}
