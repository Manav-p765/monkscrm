<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Rules\UniqueIgnoringCase;
use Illuminate\Validation\Rule;

/**
 * Shared rules and plain-language messages for the invite and edit user forms.
 */
class UserFields
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(?int $ignoreUserId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:191',
                new UniqueIgnoringCase('users', 'email', $ignoreUserId, 'Someone already uses this email address.')],
            'role' => ['required', Rule::enum(Role::class)],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'job_title' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+()\-.\s]*$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.required' => 'Enter the person’s full name.',
            'name.max' => 'Keep the name under 100 characters.',
            'email.required' => 'Enter the email address they’ll sign in with.',
            'email.email' => 'Enter a valid email address, e.g. priya.patel@hybridmonks.com.',
            'role.required' => 'Choose a role.',
            'role.enum' => 'Choose a role from the list.',
            'team_id.exists' => 'Choose a team from the list.',
            'job_title.max' => 'Keep the job title under 150 characters.',
            'phone.regex' => 'Use only digits, spaces and + - ( ) in the phone number.',
            'phone.max' => 'Keep the phone number under 50 characters.',
        ];
    }
}
