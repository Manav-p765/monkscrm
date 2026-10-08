<?php

namespace App\Http\Requests;

use App\Rules\UniqueIgnoringCase;
use App\Support\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Add or rename a lead source, or turn it on/off. The default source can't be turned off.
 */
class SourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : ! $this->route('source'),
        ]);
        $this->errorBag = $this->route('source') ? 'source'.$this->route('source')->id : 'source';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100',
                new UniqueIgnoringCase('lead_sources', 'name', $this->route('source')?->id, 'A source with this name already exists.')],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a name for the source.',
            'name.max' => 'Keep the name under 100 characters.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $source = $this->route('source');
            if ($source && ! $this->boolean('is_active') && $source->id === (int) Settings::get('default_source_id')) {
                $validator->errors()->add('is_active', 'This is the default source for new leads. Choose another default first.');
            }
        }];
    }
}
