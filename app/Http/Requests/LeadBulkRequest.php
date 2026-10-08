<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk action: ids[] (max 500) + op "status:ID", "assign:ID|none", "tag_add:ID", "tag_remove:ID", "archive".
 */
class LeadBulkRequest extends FormRequest
{
    public const MAX = 500;

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Lead::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['integer', 'distinct'],
            'op' => ['required', 'string', 'regex:/^(status:\d+|assign:(\d+|none)|tag_add:\d+|tag_remove:\d+|archive)$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one lead first.',
            'ids.max' => 'You can change up to '.self::MAX.' leads at once. Select fewer and try again.',
            'op.*' => 'Choose an action from the menu.',
        ];
    }

    /** @return array{0: string, 1: string|null} */
    public function operation(): array
    {
        $parts = explode(':', $this->validated('op'), 2);

        return [$parts[0], $parts[1] ?? null];
    }
}
