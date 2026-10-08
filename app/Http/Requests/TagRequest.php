<?php

namespace App\Http\Requests;

use App\Rules\UniqueIgnoringCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add, rename or recolour a tag. Names are unique ignoring case; colours from the prototype palette.
 */
class TagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
        $this->errorBag = $this->route('tag') ? 'tag'.$this->route('tag')->id : 'tag';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100',
                new UniqueIgnoringCase('tags', 'name', $this->route('tag')?->id, 'A tag with this name already exists.')],
            'color' => ['required', Rule::in(array_keys(config('crm.palette')))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a name for the tag.',
            'name.max' => 'Keep the name under 100 characters.',
            'color.in' => 'Choose one of the colours in the list.',
        ];
    }

    public function tagData(): array
    {
        return ['name' => $this->validated('name'), 'color' => config('crm.palette.'.$this->validated('color').'.hex')];
    }
}
