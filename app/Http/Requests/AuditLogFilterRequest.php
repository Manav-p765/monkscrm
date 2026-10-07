<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters on the Audit Log page. Access is enforced by the route (can:view-audit-log).
 */
class AuditLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view-audit-log');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'action' => ['nullable', 'string', Rule::in(array_keys(config('crm.audit_actions')))],
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
            'user.exists' => 'Choose a user from the list.',
            'action.in' => 'Choose an action from the list.',
            'from.date_format' => 'Enter the start date as a date, e.g. 2026-10-01.',
            'to.date_format' => 'Enter the end date as a date, e.g. 2026-10-31.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
