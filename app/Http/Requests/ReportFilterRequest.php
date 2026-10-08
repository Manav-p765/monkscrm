<?php

namespace App\Http\Requests;

use App\Support\Dates;
use Illuminate\Foundation\Http\FormRequest;

/** Reports date range (IST days). Defaults to the last 30 days. */
class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view-reports');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.date_format' => 'Enter the start date as a date.',
            'to.date_format' => 'Enter the end date as a date.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }

    /** @return array{from: string, to: string} */
    public function range(): array
    {
        return [
            'from' => $this->validated('from') ?? now(Dates::zone())->subDays(29)->toDateString(),
            'to' => $this->validated('to') ?? now(Dates::zone())->toDateString(),
        ];
    }
}
