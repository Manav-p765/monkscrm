<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Invite a user: no password field anywhere. The user sets their own via the invite link.
 */
class InviteUserRequest extends FormRequest
{
    protected $errorBag = 'invite';

    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return UserFields::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return UserFields::messages();
    }
}
