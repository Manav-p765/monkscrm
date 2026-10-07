<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Models\Lead;
use App\Models\Setting;
use App\Rules\EnabledCurrency;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_inr_uses_rupee_symbol_and_indian_grouping(): void
    {
        $this->assertSame('₹1,00,000', Money::format(10000000, Currency::INR));
        $this->assertSame('₹12,34,567.89', Money::format(123456789, 'INR'));
        $this->assertSame('₹1,23,45,678.90', Money::format(1234567890, 'INR'));
        $this->assertSame('₹999', Money::format(99900, 'INR'));
        $this->assertSame('₹1,000', Money::format(100000, 'INR'));
        $this->assertSame('₹0.05', Money::format(5, 'INR'));
    }

    public function test_usd_and_other_currencies_use_standard_grouping(): void
    {
        $this->assertSame('$100,000', Money::format(10000000, Currency::USD));
        $this->assertSame('$100,000.50', Money::format(10000050, 'USD'));
        $this->assertSame('$1,234,567', Money::format(123456700, 'USD'));
        $this->assertSame('€9.99', Money::format(999, 'EUR'));
        $this->assertSame('£1,000', Money::format(100000, 'GBP'));
        $this->assertSame('A$2,500', Money::format(250000, 'AUD'));
        $this->assertSame('CA$10', Money::format(1000, 'CAD'));
    }

    public function test_mixed_currency_totals_are_never_added_together(): void
    {
        $this->seed(DatabaseSeeder::class);
        Lead::factory()->create(['deal_value' => 10000, 'currency' => 'USD']);
        Lead::factory()->create(['deal_value' => 5000, 'currency' => 'USD']);
        Lead::factory()->create(['deal_value' => 35000000, 'currency' => 'INR']);
        Lead::factory()->create(['deal_value' => null, 'currency' => 'INR']);

        $totals = Money::totals(Lead::query()->latest());

        $this->assertSame(['USD' => 15000, 'INR' => 35000000], $totals);
        $this->assertSame('$150 · ₹3,50,000', Money::formatTotals($totals));
        $this->assertStringContainsString('$150 · ₹3,50,000', Blade::render('<x-money :totals="$t" />', ['t' => $totals]));
        $this->assertStringContainsString('—', Blade::render('<x-money :totals="[]" />'));
    }

    public function test_new_leads_use_the_default_currency_which_must_be_enabled(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame([Currency::USD, Currency::INR], Currency::enabled());
        $this->assertSame(Currency::USD, Currency::default());

        Setting::where('key', 'default_currency')->update(['value' => json_encode('INR')]);
        $this->assertSame(Currency::INR, Lead::factory()->create(['currency' => null])->currency);

        // A default that is not enabled falls back to the first enabled currency.
        Setting::where('key', 'default_currency')->update(['value' => json_encode('EUR')]);
        $this->assertSame(Currency::USD, Currency::default());
    }

    public function test_disabled_currency_still_displays_and_stays_valid_on_existing_leads(): void
    {
        $this->seed(DatabaseSeeder::class);
        Setting::where('key', 'enabled_currencies')->update(['value' => json_encode(['USD', 'INR', 'GBP'])]);
        $lead = Lead::factory()->create(['deal_value' => 250000, 'currency' => 'GBP']);

        Setting::where('key', 'enabled_currencies')->update(['value' => json_encode(['USD', 'INR'])]);
        $lead = $lead->fresh();

        $this->assertSame(Currency::GBP, $lead->currency);
        $this->assertStringContainsString('£2,500', Blade::render(
            '<x-money :amount="$l->deal_value" :currency="$l->currency" />', ['l' => $lead]
        ));

        // Editing that lead may keep GBP; other leads cannot pick it.
        $this->assertTrue(Validator::make(['currency' => 'GBP'], ['currency' => [new EnabledCurrency($lead->currency)]])->passes());
        $this->assertTrue(Validator::make(['currency' => 'GBP'], ['currency' => [new EnabledCurrency]])->fails());
    }

    public function test_validation_rejects_currencies_that_are_not_enabled(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rules = ['currency' => ['required', new EnabledCurrency]];

        $this->assertTrue(Validator::make(['currency' => 'USD'], $rules)->passes());
        $this->assertTrue(Validator::make(['currency' => 'INR'], $rules)->passes());

        foreach (['EUR', 'XYZ', 'usd', ['USD']] as $bad) {
            $validator = Validator::make(['currency' => $bad], $rules);
            $this->assertTrue($validator->fails(), 'Accepted: '.json_encode($bad));
            $this->assertSame('Choose one of the currencies in the list.', $validator->errors()->first('currency'));
        }
    }
}
