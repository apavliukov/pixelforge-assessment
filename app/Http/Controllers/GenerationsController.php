<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\EstimateGeneration;
use App\Actions\SubmitGeneration;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PricingUnavailableException;
use App\Exceptions\TooManyUnfinishedGenerationsException;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class GenerationsController extends Controller
{
    public const string PRICING_UNAVAILABLE = "Pricing is temporarily unavailable, so generations can't be submitted right now.";

    private const int PER_PAGE = 15;

    private const ImageSize DEFAULT_SIZE = ImageSize::Square;

    private const ImageQuality DEFAULT_QUALITY = ImageQuality::Medium;

    /**
     * The generation form and the user's history. The estimate is a closure, so a refresh of the
     * history alone does not recalculate it.
     */
    public function index(Request $request, EstimateGeneration $estimateGeneration): Response
    {
        $generations = $request->user()->generations();

        return Inertia::render('generations/Index', [
            'generations' => fn () => GenerationResource::collection($generations->clone()->latest()->paginate(self::PER_PAGE)),
            'unfinishedCount' => fn (): int => $generations->clone()->unfinished()->count(),
            'maxUnfinished' => SubmitGeneration::MAX_UNFINISHED_PER_USER,
            'limits' => [
                'promptLength' => StoreGenerationRequest::MAX_PROMPT_LENGTH,
                'referenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'defaults' => [
                'size' => self::DEFAULT_SIZE->value,
                'quality' => self::DEFAULT_QUALITY->value,
            ],
            'estimate' => function () use ($estimateGeneration): ?array {
                try {
                    return $estimateGeneration('', self::DEFAULT_SIZE, self::DEFAULT_QUALITY, 0)->toDisplay();
                } catch (PricingUnavailableException) {
                    return null;
                }
            },
        ]);
    }

    /**
     * Submits a generation. A refusal comes back as a form error, which keeps what the user typed.
     */
    public function store(StoreGenerationRequest $request, SubmitGeneration $submitGeneration): RedirectResponse
    {
        try {
            $submitGeneration($request->toInput());
        } catch (TooManyUnfinishedGenerationsException) {
            throw ValidationException::withMessages([
                'generation' => 'You have '.SubmitGeneration::MAX_UNFINISHED_PER_USER.' generations in progress. Wait for one to finish.',
            ]);
        } catch (PricingUnavailableException) {
            throw ValidationException::withMessages(['generation' => self::PRICING_UNAVAILABLE]);
        }

        return to_route('generations.index');
    }
}
