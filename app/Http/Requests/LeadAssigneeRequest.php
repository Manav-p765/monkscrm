<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change a lead's assignee from the panel. Empty = unassigned. Who may assign to whom is
 * LeadPolicy::assign, checked by the controller once the user is known.
 */
class LeadAssigneeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['assigned_to.*' => 'Choose an active user from the list.'];
    }
}
