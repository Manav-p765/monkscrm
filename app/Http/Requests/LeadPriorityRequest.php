<?php

namespace App\Http\Requests;

use App\Enums\LeadPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Change a lead's priority from the panel. Empty = no priority. */
class LeadPriorityRequest extends FormRequest
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
        return ['priority' => ['nullable', Rule::enum(LeadPriority::class)]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['priority.*' => 'Choose a priority from the list.'];
    }
}
