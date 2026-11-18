<?php

declare(strict_types=1);

namespace App\Pricing;

use InvalidArgumentException;

/**
 * An exact amount of US dollars, held as whole nanodollars (1 USD = 1,000,000,000).
 */
final readonly class Money
{
    private const int NANO_PER_DOLLAR = 1_000_000_000;

    private function __construct(public int $nano) {}

    public static function fromNano(int $nano): self
    {
        if ($nano < 0) {
            throw new InvalidArgumentException("Money cannot be negative, got {$nano} nanodollars.");
        }

        return new self($nano);
    }

    /**
     * Reads a decimal string such as "12.50" or "0.046125000" without going through a float.
     */
    public static function fromDecimalString(string $decimal): self
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', $decimal, $matches) !== 1) {
            throw new InvalidArgumentException("\"{$decimal}\" is not a decimal amount with at most nine decimals.");
        }

        $dollars = (int) $matches[1];
        $fraction = (int) mb_str_pad($matches[2] ?? '', 9, '0');

        return new self($dollars * self::NANO_PER_DOLLAR + $fraction);
    }

    public function plus(self $other): self
    {
        return new self($this->nano + $other->nano);
    }

    public function times(int $multiplier): self
    {
        return self::fromNano($this->nano * $multiplier);
    }

    /**
     * Multiplies by a factor in basis points (12,500 = ×1.25); a fraction of a nanodollar is
     * rounded up, in the operator's favour.
     */
    public function applyBasisPoints(int $basisPoints): self
    {
        return self::fromNano(intdiv($this->nano * $basisPoints + 9_999, 10_000));
    }

    /**
     * Four decimals for display, rounded up so a shown actual cost never exceeds a shown
     * maximum estimate through rounding alone.
     */
    public function format(): string
    {
        if ($this->isZero()) {
            return '$0.00';
        }

        $tenThousandths = intdiv($this->nano + 99_999, 100_000);

        return sprintf('$%d.%04d', intdiv($tenThousandths, 10_000), $tenThousandths % 10_000);
    }

    public function isZero(): bool
    {
        return $this->nano === 0;
    }

    /**
     * The exact amount with nine decimals, the format Pixelforge uses for `cost_usd`.
     */
    public function toDecimalString(): string
    {
        return sprintf('%d.%09d', intdiv($this->nano, self::NANO_PER_DOLLAR), $this->nano % self::NANO_PER_DOLLAR);
    }
}
