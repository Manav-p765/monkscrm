<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Change a lead's status from the panel or the pipeline (drag, "Move to…"). Active statuses only. */
class LeadStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lead'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lead_status_id' => ['required', 'integer', Rule::exists('lead_statuses', 'id')->where('is_active', true)],
            'via' => ['nullable', Rule::in(['panel', 'kanban'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lead_status_id.required' => 'Choose a status.',
            'lead_status_id.exists' => 'Choose an active status.',
        ];
    }
}
