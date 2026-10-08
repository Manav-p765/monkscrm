<?php

namespace App\Http\Requests;

use App\Http\Controllers\FollowUpController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Follow-ups page: tab (overdue / today / upcoming / completed) and, for admins and managers, assignee. */
class FollowUpListRequest extends FormRequest
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
        return [
            'tab' => ['nullable', Rule::in(array_keys(FollowUpController::TABS))],
            'assigned' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tab.in' => 'Choose a tab from the list.',
            'assigned.*' => 'Choose someone from the list.',
        ];
    }
}
