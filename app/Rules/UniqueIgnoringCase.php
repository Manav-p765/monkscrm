<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Unique ignoring case ("VIP" and "vip" are the same), matching our lower(column) unique indexes.
 *
 *     new UniqueIgnoringCase('teams', 'name', ignoreId: $team?->id, message: 'A team with this name already exists.')
 */
class UniqueIgnoringCase implements ValidationRule
{
    public function __construct(
        private string $table,
        private string $column = 'name',
        private ?int $ignoreId = null,
        private ?string $message = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // Static identifiers from code, bound value: no interpolated user input.
        $exists = DB::table($this->table)
            ->whereRaw("lower({$this->column}) = ?", [mb_strtolower(trim($value))])
            ->when($this->ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            $fail($this->message ?? 'This name is already used. Choose a different one.');
        }
    }
}
