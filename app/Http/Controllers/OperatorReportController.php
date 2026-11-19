<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OperatorReportRequest;
use App\Models\Generation;
use App\Queries\OperatorReportQuery;
use App\Queries\ReconciliationQuery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

final class OperatorReportController extends Controller
{
    /**
     * The operator report for a period. Reconciliation asks the provider, so it is loaded after
     * the page: our own figures do not wait for it and survive its failure. The other props are
     * closures so that the request that loads it does not build them a second time.
     */
    public function show(OperatorReportRequest $request, OperatorReportQuery $reports, ReconciliationQuery $reconciliations): Response
    {
        $period = $request->period();

        return Inertia::render('operator/Report', [
            'period' => $period->toArray(),
            'report' => static fn (): array => $reports->get($period)->toArray(),
            'unfinishedCount' => static fn (): int => Generation::query()->unfinished()->count(),
            'aboveEstimate' => static fn (): array => $reports->chargedAboveEstimate($period)
                ->map(static fn (Generation $generation): array => [
                    'id' => $generation->id,
                    'user' => ['name' => $generation->user->name, 'email' => $generation->user->email],
                    'completedAt' => $generation->completed_at?->toIso8601String(),
                    'estimateMax' => $generation->estimate_max->toDecimalString(),
                    'userCharge' => $generation->user_charge?->toDecimalString(),
                    'excess' => $generation->user_charge?->minus($generation->estimate_max)->toDecimalString(),
                ])
                ->all(),
            'reconciliation' => Inertia::defer(static function () use ($reports, $reconciliations, $period): ?array {
                try {
                    return $reconciliations->get($reports->get($period), $period)->toArray();
                } catch (ConnectionException|RequestException|UnexpectedValueException) {
                    return null;
                }
            }),
        ]);
    }
}
