<?php

declare(strict_types=1);

namespace App\Casts;

use App\Pricing\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores a `Money` amount as a whole number of nanodollars in a `bigint` column. Eloquent passes
 * whatever was assigned, including untyped `create([...])` values, so anything but `Money` is
 * refused here rather than stored as a number of unknown unit.
 *
 * @implements CastsAttributes<Money, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::fromNano((int) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("The \"{$key}\" attribute only accepts a Money amount.");
        }

        return $value->nano;
    }
}
