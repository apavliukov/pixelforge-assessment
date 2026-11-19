<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a generation is in its life. Waiting means the provider has not accepted it yet; the
 * other four are the provider's own statuses.
 */
enum GenerationStatus: string
{
    case Waiting = 'waiting';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * The status in the user's words.
     */
    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting to start',
            self::Queued => 'Queued',
            self::Processing => 'Generating',
            self::Succeeded => 'Done',
            self::Failed => 'Failed',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Whether a generation can move from the other status to this one. Statuses arrive out of
     * order, so the order of the statuses themselves, not the order of arrival, decides.
     */
    public function isAheadOf(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Waiting => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
