<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Edit a user (name, email, role, team, job title, phone). Role changes also go through
 * UserPolicy::changeRole (no self-demotion, always one active admin); a refusal shows next to Role.
 */
class UpdateUserRequest extends FormRequest
{
    protected $errorBag = 'editUser';

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
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
        return UserFields::rules($this->route('user')->id);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return UserFields::messages();
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $role = Role::tryFrom((string) $this->input('role'));
                if (! $role || $validator->errors()->has('role')) {
                    return;
                }

                $response = Gate::inspect('changeRole', [$this->route('user'), $role]);
                if ($response->denied()) {
                    $validator->errors()->add('role', $response->message() ?: 'You can’t give this user that role.');
                }
            },
        ];
    }
}
