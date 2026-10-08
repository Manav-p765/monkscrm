<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Add or edit a note. Authorization (visibility, 15-minute author edit window) is in the controller. */
class NoteRequest extends FormRequest
{
    protected $errorBag = 'note';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Write something in the note first.',
            'body.max' => 'Keep the note under 5,000 characters.',
        ];
    }
}
