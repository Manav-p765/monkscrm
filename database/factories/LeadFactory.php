<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\LeadPriority;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company' => fake()->optional(0.8)->company(),
            'job_title' => fake()->optional(0.6)->jobTitle(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional(0.8)->phoneNumber(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => fake()->randomElement(['India', 'United States', 'United Kingdom', 'UAE', 'Singapore']),
            'website' => fake()->optional(0.4)->url(),
            'description' => fake()->optional(0.5)->sentence(12),
            // An early open status (no milestone), so tests that move a lead always change something.
            'lead_status_id' => fn () => LeadStatus::query()->where('type', 'open')->whereNull('milestone')->inRandomOrder()->value('id')
                ?? LeadStatus::create(['name' => 'New', 'color' => '#2563eb', 'type' => 'open'])->id,
            'lead_source_id' => fn () => LeadSource::query()->inRandomOrder()->value('id'),
            'priority' => fake()->optional(0.7)->randomElement(LeadPriority::cases()),
            'created_by' => User::factory(),
            'next_follow_up_at' => fake()->optional(0.5)->dateTimeBetween('-10 days', '+20 days'),
            // Minor units (cents/paise).
            'deal_value' => fake()->boolean(60) ? fake()->numberBetween(50, 5000) * 10000 : null,
            'currency' => fake()->randomElement(Currency::DEFAULT_ENABLED),
        ];
    }
}
