<?php

declare(strict_types=1);

namespace App\Dto\OperatorReport;

/**
 * One user's succeeded generations completed on one UTC day.
 */
final readonly class ReportRow
{
    public function __construct(
        public string $userName,
        public string $userEmail,
        public ReportFigures $figures,
    ) {}

    /**
     * @return array{user: array{name: string, email: string}, generations: int, userCharge: string, providerCost: string, profit: string}
     */
    public function toArray(): array
    {
        return ['user' => ['name' => $this->userName, 'email' => $this->userEmail], ...$this->figures->toArray()];
    }
}
