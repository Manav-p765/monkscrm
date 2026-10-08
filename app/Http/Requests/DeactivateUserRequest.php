<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deactivate a user, optionally reassigning their open leads and pending follow-ups
 * to another active user (empty = leave them unassigned).
 */
class DeactivateUserRequest extends FormRequest
{
    protected $errorBag = 'deactivate';

    public function authorize(): bool
    {
        return $this->user()->can('deactivate', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reassign_to' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('is_active', true),
                Rule::notIn([$this->route('user')->id]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reassign_to.exists' => 'Choose an active user to take over, or leave them unassigned.',
            'reassign_to.not_in' => 'Choose someone other than the user you are deactivating.',
        ];
    }
}
