<?php

declare(strict_types=1);

use App\Pricing\Money;

it('reads a decimal string as exact nanodollars', function (string $decimal, int $nano): void {
    expect(Money::fromDecimalString($decimal)->nano)->toBe($nano);
})->with([
    'rate per 1M tokens' => ['12.50', 12_500_000_000],
    'nine decimals' => ['0.046125000', 46_125_000],
    'whole dollars' => ['3', 3_000_000_000],
    'zero' => ['0.00', 0],
]);

it('rejects text that is not a decimal number', function (string $decimal): void {
    Money::fromDecimalString($decimal);
})->with([
    'comma separator' => ['12,50'],
    'more than nine decimals' => ['0.0000000001'],
    'negative' => ['-1.00'],
    'empty' => [''],
    'exponent' => ['1e3'],
])->throws(InvalidArgumentException::class);

it('writes an amount as a nine-decimal string', function (): void {
    expect(Money::fromNano(373_875_000)->toDecimalString())->toBe('0.373875000')
        ->and(Money::fromNano(12_500_000_000)->toDecimalString())->toBe('12.500000000');
});

it('adds and multiplies without rounding', function (): void {
    $sum = Money::fromNano(5_000)->times(115)->plus(Money::fromNano(12_500)->times(8_000));

    expect($sum->nano)->toBe(100_575_000);
});

it('knows when it is zero', function (): void {
    expect(Money::fromNano(0)->isZero())->toBeTrue()
        ->and(Money::fromNano(1)->isZero())->toBeFalse();
});

it('applies a markup in basis points, rounding up to a nanodollar', function (int $nano, int $expected): void {
    expect(Money::fromNano($nano)->applyBasisPoints(12_500)->nano)->toBe($expected);
})->with([
    'worked example' => [373_875_000, 467_343_750],
    'fractional result' => [1, 2],
    'zero' => [0, 0],
]);

it('displays an amount with four decimals, rounding up', function (int $nano, string $displayed): void {
    expect(Money::fromNano($nano)->format())->toBe($displayed);
})->with([
    'sub-cent remainder' => [467_343_750, '$0.4674'],
    'remainder below the fourth decimal' => [100_000_001, '$0.1001'],
    'already fits' => [258_000_000, '$0.2580'],
    'whole dollars' => [12_000_000_000, '$12.0000'],
    'zero' => [0, '$0.00'],
]);

it('gives the displayed and the exact amount together', function (int $nano, array $display): void {
    expect(Money::fromNano($nano)->toDisplay())->toBe($display);
})->with([
    'sub-cent remainder' => [467_343_750, ['formatted' => '$0.4674', 'exact' => '0.467343750']],
    'zero' => [0, ['formatted' => '$0.00', 'exact' => '0.000000000']],
]);
