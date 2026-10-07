<?php

namespace App\Enums;

use App\Models\Setting;

/**
 * The fixed list of currencies an admin can enable (setting `enabled_currencies`).
 * Deal values are stored per lead in minor units (cents/paise) with an ISO 4217 code.
 * Format money only through App\Support\Money or <x-money>, never by hand.
 */
enum Currency: string
{
    case USD = 'USD';
    case INR = 'INR';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case AUD = 'AUD';
    case CAD = 'CAD';

    public const DEFAULT_ENABLED = ['USD', 'INR'];

    public const DEFAULT = 'USD';

    public function symbol(): string
    {
        return match ($this) {
            self::USD => '$',
            self::INR => '₹',
            self::EUR => '€',
            self::GBP => '£',
            self::AUD => 'A$',
            self::CAD => 'CA$',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::USD => 'US dollar',
            self::INR => 'Indian rupee',
            self::EUR => 'Euro',
            self::GBP => 'British pound',
            self::AUD => 'Australian dollar',
            self::CAD => 'Canadian dollar',
        };
    }

    /** All supported currencies use 2 decimal places. */
    public function minorUnits(): int
    {
        return 2;
    }

    /**
     * Currencies new or edited leads may use, in the fixed list order.
     * Existing leads keep their currency even if it is disabled later.
     *
     * @return list<self>
     */
    public static function enabled(): array
    {
        $codes = (array) Setting::getValue('enabled_currencies', self::DEFAULT_ENABLED);
        $enabled = array_values(array_filter(self::cases(), fn (self $c) => in_array($c->value, $codes, true)));

        return $enabled ?: [self::from(self::DEFAULT)];
    }

    /** The default for new leads; always one of the enabled currencies. */
    public static function default(): self
    {
        $enabled = self::enabled();
        $default = self::tryFrom((string) Setting::getValue('default_currency', self::DEFAULT));

        return in_array($default, $enabled, true) ? $default : $enabled[0];
    }
}
