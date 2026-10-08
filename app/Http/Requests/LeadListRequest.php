<?php

namespace App\Http\Requests;

use App\Models\Lead;
use App\Support\LeadFilters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lead list / pipeline filters (query string). The archived view is admin only.
 */
class LeadListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Lead::class)
            && (! $this->boolean('archived') || $this->user()->can('viewArchived', Lead::class));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...LeadFilters::rules(), 'archived' => ['nullable', 'boolean']];
    }

    /** Validated filters without the archived flag. */
    public function filters(): array
    {
        return collect($this->validated())->except('archived')->all();
    }
}
