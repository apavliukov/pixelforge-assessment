<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OperatorReportRequest;
use App\Models\Generation;
use App\Queries\OperatorReportQuery;
use App\Queries\ReconciliationQuery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

final class OperatorReportController extends Controller
{
    /**
     * The operator report for a period. Reconciliation asks the provider, so it is loaded after
     * the page: our own figures do not wait for it and survive its failure. The other props are
     * closures so that the request that loads it does not build them a second time.
     *
     * A request without both dates is sent to the address that names them. Otherwise the
     * request loading the reconciliation would work the default period out again, and around
     * midnight UTC compare different days than the page shows.
     */
    public function show(
        OperatorReportRequest $request,
        OperatorReportQuery $operatorReportQuery,
        ReconciliationQuery $reconciliationQuery,
    ): Response|RedirectResponse {
        $period = $request->period();

        if (! $request->namesThePeriod()) {
            return to_route('operatorReport.show', $period->toQuery());
        }

        return Inertia::render('operator/Report', [
            'period' => $period->toArray(),
            'report' => static fn (): array => $operatorReportQuery->get($period)->toArray(),
            'unfinishedCount' => $operatorReportQuery->unfinishedCount(...),
            'aboveEstimate' => static fn (): array => $operatorReportQuery->chargedAboveEstimate($period)
                ->map(static fn (Generation $generation): array => [
                    'id' => $generation->id,
                    'user' => ['name' => $generation->user->name, 'email' => $generation->user->email],
                    'completedAt' => $generation->completed_at?->toIso8601ZuluString(),
                    'estimateMax' => $generation->estimate_max->toDecimalString(),
                    'userCharge' => $generation->user_charge?->toDecimalString(),
                    'excess' => $generation->user_charge?->minus($generation->estimate_max)->toDecimalString(),
                ])
                ->all(),
            'reconciliation' => Inertia::defer(static function () use ($operatorReportQuery, $reconciliationQuery, $period): ?array {
                try {
                    return $reconciliationQuery->get($operatorReportQuery->get($period), $period)->toArray();
                } catch (ConnectionException|RequestException|UnexpectedValueException) {
                    return null;
                }
            }),
        ]);
    }
}
