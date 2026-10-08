<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Enums\LeadPriority;
use App\Models\Lead;
use App\Models\User;
use App\Rules\EnabledCurrency;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / edit a lead (prototype LeadFormModal). Required: first name or company, and email or
 * phone. Status/source must be active (an existing lead may keep a since-disabled one). Deal value in
 * the chosen currency (enabled currencies only; a lead keeps a since-disabled currency).
 * Sales may only assign to themselves (LeadPolicy). Duplicate rules are checked by the controller.
 */
class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lead = $this->route('lead');

        return $lead ? $this->user()->can('update', $lead) : $this->user()->can('create', Lead::class);
    }

    protected function prepareForValidation(): void
    {
        $trim = fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        $this->merge(collect($this->only([
            'first_name', 'last_name', 'company', 'job_title', 'email', 'secondary_email', 'phone', 'secondary_phone',
            'website', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country', 'description',
            'initial_note', 'deal_value', 'priority', 'assigned_to',
        ]))->map($trim)->all());

        if ($this->filled('website') && ! preg_match('#^https?://#i', (string) $this->input('website'))) {
            $this->merge(['website' => 'https://'.$this->input('website')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $lead = $this->route('lead');
        $activeOr = fn (string $table, ?int $current) => Rule::exists($table, 'id')->where(
            fn ($q) => $current ? $q->where(fn ($w) => $w->where('is_active', true)->orWhere('id', $current)) : $q->where('is_active', true)
        );

        return [
            'first_name' => ['nullable', 'string', 'max:100', 'required_without:company'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:191', 'required_without:first_name'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:191', 'required_without:phone'],
            'secondary_email' => ['nullable', 'string', 'email:rfc', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+()\-.\s]*$/', 'required_without:email'],
            'secondary_phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+()\-.\s]*$/'],
            // http(s) only: a javascript: URL in an href would be stored XSS.
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'initial_note' => ['nullable', 'string', 'max:5000'],
            'lead_status_id' => ['required', 'integer', $activeOr('lead_statuses', $lead?->lead_status_id)],
            'lead_source_id' => ['required', 'integer', $activeOr('lead_sources', $lead?->lead_source_id)],
            'priority' => ['nullable', Rule::enum(LeadPriority::class)],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['integer', 'distinct', Rule::exists('tags', 'id')],
            'currency' => ['required', 'string', new EnabledCurrency($lead?->currency)],
            'deal_value' => ['nullable', 'string', 'max:30'],
            'confirm_duplicate' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required_without' => 'Enter a first name or a company name.',
            'company.required_without' => 'Enter a company name or a first name.',
            'email.required_without' => 'Enter an email address or a phone number.',
            'phone.required_without' => 'Enter a phone number or an email address.',
            'email.email' => 'Enter a valid email address, e.g. name@company.com.',
            'secondary_email.email' => 'Enter a valid email address, e.g. name@company.com.',
            'phone.regex' => 'Use only digits, spaces and + - ( ) in the phone number.',
            'secondary_phone.regex' => 'Use only digits, spaces and + - ( ) in the phone number.',
            'website.url' => 'Enter a website address starting with http:// or https://.',
            'lead_status_id.required' => 'Choose a status.',
            'lead_status_id.exists' => 'Choose an active status from the list.',
            'lead_source_id.required' => 'Choose a source.',
            'lead_source_id.exists' => 'Choose an active source from the list.',
            'priority.enum' => 'Choose a priority from the list.',
            'assigned_to.exists' => 'Choose an active user from the list.',
            'tags.*.exists' => 'Choose tags from the list.',
            'currency.required' => 'Choose a currency.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('currency') && ($currency = Currency::tryFrom((string) $this->input('currency')))) {
                if (Money::parse($this->input('deal_value'), $currency) === false) {
                    $validator->errors()->add('deal_value', 'Enter the deal value as a number, e.g. 25000 or 25000.50.');
                }
            }

            if (! $validator->errors()->has('assigned_to') && $this->has('assigned_to')) {
                $to = $this->input('assigned_to') ? User::find($this->input('assigned_to')) : null;
                $lead = $this->route('lead');
                $allowed = $lead ? $this->user()->can('assign', [$lead, $to]) : $this->user()->can('assignTo', [Lead::class, $to]);
                $unchanged = $lead && $lead->assigned_to === $to?->id;
                if (! $allowed && ! $unchanged) {
                    $validator->errors()->add('assigned_to', $this->user()->can('assignTo', [Lead::class, null])
                        ? 'Choose an active user from the list.'
                        : 'You can only assign leads to yourself.');
                }
            }
        }];
    }

    public function dealValueMinor(): ?int
    {
        $value = Money::parse($this->validated('deal_value'), $this->validated('currency'));

        return $value === false ? null : $value;
    }
}
