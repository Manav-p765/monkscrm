<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Lead export: the list filters plus the file format (CSV or Excel) and the columns to include.
 * Unknown column keys are ignored by the controller.
 */
class LeadExportRequest extends LeadListRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['format.in' => 'Choose CSV or Excel for the export.'];
    }

    /** Validated filters without the archived flag, format and columns. */
    public function filters(): array
    {
        return collect(parent::filters())->except(['format', 'columns'])->all();
    }

    public function fileFormat(): string
    {
        return $this->validated('format') ?? 'csv';
    }
}
