<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Dto\OperatorReport\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class OperatorReportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The period is checked as a whole, after a missing date has been filled in. An error goes
     * to the end date when the operator gave one, and to the start date otherwise.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $period = $this->period();
                $field = $this->filled('end_date') ? 'end_date' : 'start_date';

                $error = match (true) {
                    $period->end->greaterThan(CarbonImmutable::today('UTC')) => 'The period must not end in the future.',
                    $period->endsBeforeItStarts() => $this->filled('end_date')
                        ? 'The end date must not be before the start date.'
                        : 'The start date must not be in the future.',
                    $period->days() > ReportPeriod::MAX_DAYS => sprintf('The period must not be longer than %d days.', ReportPeriod::MAX_DAYS),
                    default => null,
                };

                if ($error !== null) {
                    $validator->errors()->add($field, $error);
                }
            },
        ];
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::fromDates(
            $this->filled('start_date') ? $this->string('start_date')->toString() : null,
            $this->filled('end_date') ? $this->string('end_date')->toString() : null,
        );
    }

    /**
     * Whether the address names both ends of the period. Only then do the page and the request
     * that later loads its reconciliation certainly mean the same days.
     */
    public function namesThePeriod(): bool
    {
        return $this->filled('start_date') && $this->filled('end_date');
    }

    /**
     * A refused period leads back to the report for the default period, where the error is
     * shown, wherever the request came from.
     */
    protected function getRedirectUrl(): string
    {
        return route('operatorReport.show', ReportPeriod::fromDates(null, null)->toQuery());
    }
}
