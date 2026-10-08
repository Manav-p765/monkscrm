<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings → Security & Rules: lead visibility for sales, duplicate override, currencies and
 * import limits. The default currency must be one of the enabled currencies.
 */
class GeneralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sales_can_view_all_leads' => $this->boolean('sales_can_view_all_leads'),
            'sales_can_view_unassigned' => $this->boolean('sales_can_view_unassigned'),
            'allow_duplicate_override' => $this->boolean('allow_duplicate_override'),
            'enabled_currencies' => array_values(array_filter((array) $this->input('enabled_currencies', []), 'is_string')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $codes = array_map(fn (Currency $c) => $c->value, Currency::cases());

        return [
            'sales_can_view_all_leads' => ['boolean'],
            'sales_can_view_unassigned' => ['boolean'],
            'allow_duplicate_override' => ['boolean'],
            'enabled_currencies' => ['required', 'array', 'min:1'],
            'enabled_currencies.*' => [Rule::in($codes)],
            'default_currency' => ['required', Rule::in($this->input('enabled_currencies', []))],
            'import_max_file_mb' => ['required', 'integer', 'min:1', 'max:20'],
            'import_max_rows' => ['required', 'integer', 'min:100', 'max:20000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'enabled_currencies.required' => 'Turn on at least one currency.',
            'enabled_currencies.min' => 'Turn on at least one currency.',
            'enabled_currencies.*.in' => 'Choose currencies from the list.',
            'default_currency.in' => 'The default currency must be one of the turned-on currencies. Turn it on, or choose another default.',
            'import_max_file_mb.max' => 'The largest allowed file size is 20 MB.',
            'import_max_file_mb.min' => 'The file size limit must be at least 1 MB.',
            'import_max_rows.max' => 'The most rows allowed per import is 20,000.',
            'import_max_rows.min' => 'The row limit must be at least 100.',
        ];
    }

    /** Values in the fixed currency order, ready for Settings::update(). */
    public function settings(): array
    {
        $data = $this->validated();
        $data['enabled_currencies'] = array_values(array_filter(
            array_map(fn (Currency $c) => $c->value, Currency::cases()),
            fn (string $code) => in_array($code, $data['enabled_currencies'], true)
        ));
        $data['import_max_file_mb'] = (int) $data['import_max_file_mb'];
        $data['import_max_rows'] = (int) $data['import_max_rows'];

        return $data;
    }
}
