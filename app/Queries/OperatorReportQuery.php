<?php

declare(strict_types=1);

namespace App\Queries;

use App\Dto\OperatorReport\OperatorReport;
use App\Dto\OperatorReport\ReportDay;
use App\Dto\OperatorReport\ReportFigures;
use App\Dto\OperatorReport\ReportPeriod;
use App\Dto\OperatorReport\ReportRow;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Per UTC day and user: the succeeded generations completed that day and their money.
 */
final readonly class OperatorReportQuery
{
    /**
     * The day is the one a generation completed on, as the provider counts it. `completed_at`
     * is stored in UTC, so its date is the UTC day. The sums are of whole nanodollars.
     */
    public function get(ReportPeriod $period): OperatorReport
    {
        $sums = $this->succeededWithin($period)
            ->toBase()
            ->selectRaw('completed_at::date as day, user_id, count(*) as generations, sum(user_charge) as user_charge, sum(provider_cost) as provider_cost')
            ->groupByRaw('1, 2')
            ->get();

        $users = User::query()->whereKey($sums->pluck('user_id')->unique())->get(['id', 'name', 'email'])->keyBy('id');

        $days = $sums
            ->groupBy('day')
            ->sortKeysDesc()
            ->map(static fn (Collection $daySums, string $date): ReportDay => new ReportDay(
                $date,
                array_values($daySums
                    ->map(static fn (stdClass $sum): ReportRow => self::row($sum, $users))
                    ->sortBy('userEmail')
                    ->all()),
            ))
            ->all();

        return new OperatorReport(array_values($days));
    }

    /**
     * Succeeded generations completed in the period whose user charge is above the maximum the
     * user was shown, most recent first.
     *
     * @return EloquentCollection<int, Generation>
     */
    public function chargedAboveEstimate(ReportPeriod $period): EloquentCollection
    {
        return $this->succeededWithin($period)
            ->with('user:id,name,email')
            ->whereColumn('user_charge', '>', 'estimate_max')
            ->latest('completed_at')
            ->get(['id', 'user_id', 'completed_at', 'estimate_max', 'user_charge']);
    }

    /**
     * Generations of all users that have no final status yet. One the provider has completed and
     * this app has not yet recorded makes today differ until its state arrives.
     */
    public function unfinishedCount(): int
    {
        return Generation::query()->unfinished()->count();
    }

    /**
     * @param  EloquentCollection<int, User>  $users  by id
     */
    private static function row(stdClass $sum, EloquentCollection $users): ReportRow
    {
        $user = $users->get($sum->user_id);

        return new ReportRow(
            userName: $user->name,
            userEmail: $user->email,
            figures: new ReportFigures(
                generations: (int) $sum->generations,
                userCharge: Money::fromNano((int) $sum->user_charge),
                providerCost: Money::fromNano((int) $sum->provider_cost),
            ),
        );
    }

    /**
     * What counts for the report: succeeded generations completed on the period's days.
     *
     * @return Builder<Generation>
     */
    private function succeededWithin(ReportPeriod $period): Builder
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->where('completed_at', '>=', $period->start)
            ->where('completed_at', '<', $period->endExclusive());
    }
}
