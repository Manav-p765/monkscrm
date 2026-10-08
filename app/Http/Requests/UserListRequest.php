<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search and filters on the Users & Teams list (admins and managers).
 */
class UserListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'team' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'status' => ['nullable', Rule::in(['active', 'invited', 'deactivated'])],
        ];
    }
}
