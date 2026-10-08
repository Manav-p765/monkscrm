<?php

namespace App\Http\Requests;

use App\Services\Imports\LeadImporter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Import wizard, Map Fields step: one CRM field (or "none") per file column, and what to do with
 * unknown statuses / sources / tags / users (creating them is admin only).
 */
class ImportMappingRequest extends FormRequest
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
        return [
            'mapping' => ['required', 'array'],
            'mapping.*' => ['required', Rule::in(['none', ...array_keys(LeadImporter::FIELDS)])],
            'unknown' => ['nullable', 'array'],
            'unknown.*' => [Rule::in($this->user()->isAdmin() ? ['default', 'create', 'skip'] : ['default', 'skip'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mapping.required' => 'Map at least a name (or company) column and an email (or phone) column.',
            'mapping.*.in' => 'Choose a CRM field from the list for every column.',
            'unknown.*.in' => 'Only an admin can create new statuses, sources or tags during an import.',
        ];
    }
}
