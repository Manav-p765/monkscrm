<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Header search as you type (?q=). Results are visibility-scoped by the controller. */
class LiveSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100']];
    }

    public function term(): string
    {
        return trim((string) $this->validated('q', ''));
    }
}
