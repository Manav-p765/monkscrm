<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Import wizard, Duplicates step: skip (default), update existing leads, or import as new. */
class ImportStrategyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('import-leads');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['strategy' => ['required', Rule::in(['skip', 'update', 'new'])]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['strategy.*' => 'Choose how to handle duplicates.'];
    }
}
