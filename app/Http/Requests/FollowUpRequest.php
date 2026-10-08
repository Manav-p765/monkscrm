<?php

namespace App\Http\Requests;

use App\Enums\FollowUpType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schedule a follow-up (type, assignee, due date/time in IST, notes), or act on one:
 * complete (optional outcome), cancel, reschedule (new due date/time), reopen.
 */
class FollowUpRequest extends FormRequest
{
    protected $errorBag = 'followUp';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->route('followUp')) {
            return [
                'action' => ['required', Rule::in(['complete', 'cancel', 'reschedule', 'reopen'])],
                'outcome' => ['nullable', 'string', 'max:191'],
                'due_at' => ['required_if:action,reschedule', 'nullable', 'date_format:Y-m-d\TH:i'],
            ];
        }

        return [
            'type' => ['required', Rule::enum(FollowUpType::class)],
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'due_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Choose the action type.',
            'assigned_to.required' => 'Choose who will do it.',
            'assigned_to.exists' => 'Choose an active user from the list.',
            'due_at.required' => 'Choose the due date and time.',
            'due_at.required_if' => 'Choose the new due date and time.',
            'due_at.date_format' => 'Enter the due date and time.',
        ];
    }
}
