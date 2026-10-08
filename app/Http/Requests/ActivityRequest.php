<?php

namespace App\Http\Requests;

use App\Enums\ActivityType;
use App\Enums\FollowUpType;
use App\Services\Leads\ActivityLogger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Log an activity (prototype "Log Interaction"): type, when (IST), description, outcome, and
 * optionally schedule a follow-up at the same time.
 */
class ActivityRequest extends FormRequest
{
    protected $errorBag = 'activity';

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
            'type' => ['required', Rule::in(array_map(fn (ActivityType $t) => $t->value, ActivityLogger::LOGGABLE))],
            'occurred_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.now(config('crm.display_timezone'))->addMinutes(5)->format('Y-m-d\TH:i')],
            'description' => ['required', 'string', 'max:5000'],
            'outcome' => ['nullable', 'string', 'max:191'],
            'schedule_follow_up' => ['nullable', 'boolean'],
            'follow_up_type' => ['required_if_accepted:schedule_follow_up', 'nullable', Rule::enum(FollowUpType::class)],
            'follow_up_due_at' => ['required_if_accepted:schedule_follow_up', 'nullable', 'date_format:Y-m-d\TH:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Choose call, email, meeting, SMS or other.',
            'occurred_at.date_format' => 'Enter the date and time it happened.',
            'occurred_at.before_or_equal' => 'The activity can’t be in the future.',
            'description.required' => 'Describe what happened.',
            'follow_up_type.required_if_accepted' => 'Choose the follow-up type.',
            'follow_up_due_at.required_if_accepted' => 'Choose when the follow-up is due.',
            'follow_up_due_at.date_format' => 'Enter the follow-up date and time.',
        ];
    }
}
