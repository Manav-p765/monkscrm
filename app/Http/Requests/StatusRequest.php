<?php

namespace App\Http\Requests;

use App\Enums\LeadMilestone;
use App\Enums\LeadStatusType;
use App\Rules\UniqueIgnoringCase;
use App\Support\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add or edit a pipeline status. Colours come from the prototype palette only. Rules: the
 * default status must stay active and Open, and the last active Open status can't be disabled.
 */
class StatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => trim((string) $this->input('description')) ?: null,
            'milestone' => $this->input('milestone') ?: null,
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : ! $this->route('status'),
        ]);
        $this->errorBag = $this->route('status') ? 'status'.$this->route('status')->id : 'status';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100',
                new UniqueIgnoringCase('lead_statuses', 'name', $this->route('status')?->id, 'A status with this name already exists.')],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['required', Rule::in(array_keys(config('crm.palette')))],
            'type' => ['required', Rule::enum(LeadStatusType::class)],
            'milestone' => ['nullable', Rule::enum(LeadMilestone::class)],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a name for the status.',
            'name.max' => 'Keep the name under 100 characters.',
            'color.in' => 'Choose one of the colours in the list.',
            'type.enum' => 'Choose Open, Won or Lost.',
            'milestone.enum' => 'Choose a milestone from the list.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $status = $this->route('status');
            if (! $status || $validator->errors()->isNotEmpty()) {
                return;
            }

            $staysOpenAndActive = $this->boolean('is_active') && $this->input('type') === LeadStatusType::Open->value;
            if ($status->id === (int) Settings::get('default_status_id') && ! $staysOpenAndActive) {
                $validator->errors()->add('is_active', 'This is the default status for new leads, so it must stay active and Open. Make another status the default first.');
            }

            if ($status->is_active && $status->type === LeadStatusType::Open && ! $staysOpenAndActive
                && ! \App\Models\LeadStatus::query()->active()->where('type', LeadStatusType::Open)->whereKeyNot($status->id)->exists()) {
                $validator->errors()->add('is_active', 'You need at least one active Open status.');
            }
        }];
    }

    /** Validated data with the palette name turned into the stored hex colour. */
    public function statusData(): array
    {
        $data = $this->validated();
        $data['color'] = config("crm.palette.{$data['color']}.hex");

        return $data;
    }
}
