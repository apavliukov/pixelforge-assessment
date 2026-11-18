<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Dto\Pixelforge\PricingData;
use App\Exceptions\PricingUnavailableException;
use App\Http\Clients\PixelforgeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use UnexpectedValueException;

/**
 * The local copy of Pixelforge's published pricing. A scheduled job refreshes it every five
 * minutes, so request code reads the cache and does not wait on Pixelforge.
 */
final readonly class PricingCatalog
{
    private const string CACHE_KEY = 'pixelforge.pricing';

    /**
     * The copy expires this long after the last successful refresh: by then refreshes have been
     * failing for a while, and estimating from it could promise a maximum below what Pixelforge
     * now charges.
     */
    private const int MAX_AGE_SECONDS = 3_600;

    public function __construct(private PixelforgeClient $pixelforge) {}

    /**
     * @throws PricingUnavailableException when there is no recent copy and fetching one fails
     */
    public function current(): PricingData
    {
        $payload = Cache::get(self::CACHE_KEY);

        if (is_array($payload)) {
            return PricingData::fromArray($payload);
        }

        try {
            return $this->refresh();
        } catch (ConnectionException|RequestException|UnexpectedValueException $exception) {
            throw new PricingUnavailableException('Pixelforge pricing is unavailable.', $exception->getCode(), previous: $exception);
        }
    }

    /**
     * Replaces the local copy. On any failure it throws and the previous copy stays.
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function refresh(): PricingData
    {
        $pricing = $this->pixelforge->pricing();

        Cache::put(self::CACHE_KEY, $pricing->payload, self::MAX_AGE_SECONDS);

        return $pricing;
    }
}
