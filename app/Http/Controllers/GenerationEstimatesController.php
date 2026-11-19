<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\EstimateGeneration;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PricingUnavailableException;
use App\Http\Requests\Generations\EstimateGenerationRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class GenerationEstimatesController extends Controller
{
    /**
     * The estimate for the generation form's current values.
     */
    public function store(EstimateGenerationRequest $request, EstimateGeneration $estimateGeneration): JsonResponse
    {
        try {
            $estimate = $estimateGeneration(
                prompt: $request->string('prompt')->toString(),
                size: $request->enum('size', ImageSize::class),
                quality: $request->enum('quality', ImageQuality::class),
                referenceImageCount: $request->integer('reference_image_count'),
            );
        } catch (PricingUnavailableException) {
            return response()->json(['message' => GenerationsController::PRICING_UNAVAILABLE], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json($estimate->toDisplay());
    }
}
