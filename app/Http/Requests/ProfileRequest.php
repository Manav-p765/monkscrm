<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * My Profile: a user edits their own name, job title and phone. Email, role and team stay admin-only.
 */
class ProfileRequest extends FormRequest
{
    protected $errorBag = 'updateProfile';

    public function authorize(): bool
    {
        return $this->user()->can('updateProfile', $this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_intersect_key(UserFields::rules(), array_flip(['name', 'job_title', 'phone']));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...UserFields::messages(), 'name.required' => 'Enter your full name.'];
    }
}
