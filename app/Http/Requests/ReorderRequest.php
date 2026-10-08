<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reorder statuses or sources: either the full new order (drag and drop) or one step up/down
 * (keyboard buttons).
 */
class ReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required_without:direction', 'array', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
            'direction' => ['required_without:ids', Rule::in(['up', 'down'])],
        ];
    }
}
