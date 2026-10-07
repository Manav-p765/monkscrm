<?php

namespace App\Rules;

use App\Enums\Currency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The currency must be enabled in Settings. When editing a lead, pass its current
 * currency so a since-disabled currency stays valid on that lead.
 */
class EnabledCurrency implements ValidationRule
{
    public function __construct(private Currency|string|null $current = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $current = $this->current instanceof Currency ? $this->current->value : $this->current;
        $enabled = array_map(fn (Currency $c) => $c->value, Currency::enabled());

        if (! is_string($value) || ! (in_array($value, $enabled, true) || $value === $current)) {
            $fail('Choose one of the currencies in the list.');
        }
    }
}
