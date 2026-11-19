<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Dto\OperatorReport\ReportPeriod;
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
     * The period is checked as a whole, after a missing date has been filled in.
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

                if ($period->endsBeforeItStarts()) {
                    $validator->errors()->add('end_date', 'The end date must not be before the start date.');

                    return;
                }

                if ($period->days() > ReportPeriod::MAX_DAYS) {
                    $validator->errors()->add('end_date', sprintf('The period must not be longer than %d days.', ReportPeriod::MAX_DAYS));
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
}
