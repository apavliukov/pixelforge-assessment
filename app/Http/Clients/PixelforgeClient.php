<?php

declare(strict_types=1);

namespace App\Http\Clients;

use App\Dto\Pixelforge\GenerationData;
use App\Dto\Pixelforge\PricingData;
use App\Models\Generation;
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

    /**
     * Sends the generation with its local identifier as the idempotency key, so a repeated send
     * returns the generation the provider already has instead of creating a second one.
     *
     * @throws ConnectionException when Pixelforge cannot be reached
     * @throws RequestException when Pixelforge answers with an error status
     * @throws UnexpectedValueException when the response is not a valid generation
     */
    public function createGeneration(Generation $generation): GenerationData
    {
        $response = $this->request()
            ->withHeader('Idempotency-Key', $generation->id)
            ->post('generations', [
                'prompt' => $generation->prompt,
                'size' => $generation->size->value,
                'quality' => $generation->quality->value,
                'reference_images' => $generation->reference_images,
                'metadata' => ['local_id' => $generation->id],
            ])
            ->throw()
            ->json() ?? [];

        return GenerationData::fromArray($response);
    }

    /**
     * @throws ConnectionException when Pixelforge cannot be reached
     * @throws RequestException when Pixelforge answers with an error status
     * @throws UnexpectedValueException when the response is not a valid generation
     */
    public function getGeneration(string $providerId): GenerationData
    {
        $response = $this->request()->get("generations/{$providerId}")->throw()->json() ?? [];

        return GenerationData::fromArray($response);
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
