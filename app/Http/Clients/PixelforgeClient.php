<?php

declare(strict_types=1);

namespace App\Http\Clients;

use App\Dto\Pixelforge\PricingData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

final readonly class PixelforgeClient
{
    private const string BASE_URL = 'https://pixelforge.promptedit.dev/v1';

    /**
     * @throws ConnectionException when Pixelforge cannot be reached
     * @throws RequestException when Pixelforge answers with an error status
     * @throws UnexpectedValueException when the response is not valid pricing
     */
    public function pricing(): PricingData
    {
        $response = $this->request()->get('pricing')->throw()->json() ?? [];

        return PricingData::fromArray($response);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken((string) config('services.pixelforge.key'))
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10);
    }
}
