<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Duplicate check while typing in the lead form: email, phone, and the lead being edited (reference). */
class DuplicateCheckRequest extends FormRequest
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
        return [
            'email' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'ignore' => ['nullable', 'string', 'max:20'],
        ];
    }
}
