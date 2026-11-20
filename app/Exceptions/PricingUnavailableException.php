<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class PricingUnavailableException extends RuntimeException
{
    /**
     * What a user is told when a generation cannot be priced, and so cannot be submitted.
     */
    public const string USER_MESSAGE = "Pricing is temporarily unavailable, so generations can't be submitted right now.";
}
