<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'crm:create-admin';

    protected $description = 'Interactively create an active admin user';

    public function handle(): int
    {
        $name = text(
            label: 'Name',
            required: true,
            validate: fn (string $value) => $this->firstError('name', $value, ['required', 'string', 'max:255']),
        );

        $email = mb_strtolower(trim(text(
            label: 'Email',
            required: true,
            validate: fn (string $value) => $this->firstError(
                'email',
                mb_strtolower(trim($value)),
                ['required', 'email', 'max:255', 'unique:users,email'],
            ),
        )));

        $password = password(
            label: 'Password',
            required: true,
            hint: 'At least 12 characters, mixed case, a number, and not found in known data breaches.',
            validate: fn (string $value) => $this->firstError('password', $value, ['required', Password::defaults()]),
        );

        password(
            label: 'Confirm password',
            required: true,
            validate: fn (string $value) => hash_equals($password, $value) ? null : 'The passwords do not match.',
        );

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => Role::Admin,
            'is_active' => true,
        ]);

        $this->components->info("Admin user created: {$user->email} (id {$user->id}).");

        return self::SUCCESS;
    }

    private function firstError(string $field, string $value, array $rules): ?string
    {
        $validator = Validator::make([$field => $value], [$field => $rules]);

        return $validator->fails() ? $validator->errors()->first($field) : null;
    }
}
