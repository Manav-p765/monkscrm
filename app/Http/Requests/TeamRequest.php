<?php

namespace App\Http\Requests;

use App\Rules\UniqueIgnoringCase;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add or rename a team. Names are unique ignoring case.
 */
class TeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-teams');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
        $this->errorBag = $this->route('team') ? 'team'.$this->route('team')->id : 'team';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100',
                new UniqueIgnoringCase('teams', 'name', $this->route('team')?->id, 'A team with this name already exists.')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a team name.',
            'name.max' => 'Keep the team name under 100 characters.',
        ];
    }
}
