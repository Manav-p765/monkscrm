<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\Currency;
use App\Enums\FollowUpType;
use App\Enums\LeadPriority;
use App\Models\FollowUp;
use App\Models\Import;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\Imports\LeadImporter;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\FollowUps;
use App\Services\Leads\LeadAssigner;
use App\Services\Leads\LeadStatusChanger;
use App\Support\Audit;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local demo data: 1 admin, 1 manager, 3 sales users in two teams, 80 leads across every status
 * (USD and INR), with notes, logged activities, follow-ups (overdue, due today, upcoming,
 * completed) and one finished import. Everything goes through the real services at back-dated
 * times, so timelines, milestones, alerts and the audit log look like real use.
 *
 * Obviously fake only: @example.com emails, 555 / 55555 phone numbers, .example websites and
 * Microsoft's fictional company names. Logins are printed to the console, never saved.
 *
 *     php artisan migrate:fresh --seeder=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    /** [status name, how many] — every status, 80 leads. */
    private const MIX = [
        ['New', 10], ['Attempted Contact', 7], ['Contacted', 9], ['Follow-Up Required', 6], ['Qualified', 8],
        ['Meeting Booked', 6], ['Proposal Sent', 6], ['Opportunity', 5], ['Won', 9], ['Lost', 5],
        ['Not Interested', 5], ['Invalid', 4],
    ];

    /** The open part of the pipeline, in order. */
    private const LADDER = ['New', 'Attempted Contact', 'Contacted', 'Follow-Up Required', 'Qualified', 'Meeting Booked', 'Proposal Sent', 'Opportunity'];

    private const COMPANIES = ['Contoso', 'Fabrikam', 'Northwind Traders', 'Tailspin Toys', 'Wingtip Toys', 'Adventure Works',
        'Litware', 'Proseware', 'Woodgrove Bank', 'Lucerne Publishing', 'Coho Winery', 'Fourth Coffee', 'Alpine Ski House',
        'Wide World Importers', 'Trey Research', 'Blue Yonder Airlines', 'Humongous Insurance', 'Southridge Video', 'VanArsdel',
        'Relecloud', 'A. Datum Corporation', 'First Up Consultants', 'Consolidated Messenger', 'Lamna Healthcare', 'Tasmanian Traders',
        'Margie’s Travel', 'Graphic Design Institute', 'City Power & Light', 'Bellows College', 'Munson’s Pickles'];

    private Generator $us;

    private Generator $in;

    private int $phone = 0;

    /** Midnight IST at the end of the real today (taken before the clock is moved back). */
    private CarbonImmutable $endOfToday;

    /** @var array<string, int> */
    private array $emails = [];

    public function run(): void
    {
        // Fake users and leads: never anywhere but a developer machine (not production, staging or CI).
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder only runs when APP_ENV=local.');
        }

        $this->call(DatabaseSeeder::class);

        if (User::query()->exists() || Lead::withTrashed()->exists()) {
            throw new RuntimeException('DemoSeeder needs an empty database: php artisan migrate:fresh --seeder=DemoSeeder');
        }

        $this->us = Faker::create('en_US');
        $this->in = Faker::create('en_IN');
        $this->us->seed(2026);
        $this->in->seed(2026);
        mt_srand(2026);

        $real = now()->toImmutable();
        $this->endOfToday = CarbonImmutable::instance(Dates::endOfToday());
        try {
            $password = Str::password(16);
            $users = $this->users($password, $real);
            foreach ($this->plan() as $i => [$status, $india]) {
                $this->lead($i, $status, $india, $users, $real);
            }
            $this->import($users['manager'], $real);
        } finally {
            Carbon::setTestNow(); // back to the real clock
        }

        $this->command?->warn('Demo logins (shown once, not saved anywhere):');
        $this->command?->table(['Role', 'Name', 'Email'], collect($users)->except('sales')->merge($users['sales'])
            ->map(fn (User $u) => [$u->role->label(), $u->name, $u->email])->values()->all());
        $this->command?->warn("Password for all demo users (this run only): {$password}");
    }

    /** @return array{admin: User, manager: User, sales: list<User>} */
    private function users(string $password, CarbonImmutable $real): array
    {
        $india = Team::create(['name' => 'India Sales']);
        $us = Team::create(['name' => 'US Sales']);
        $hash = Hash::make($password);
        $make = fn (string $name, string $role, ?Team $team, string $title, string $phone) => tap(new User([
            'name' => $name, 'email' => Str::of($name)->lower()->replace(' ', '.').'@example.com', 'password' => $hash,
            'role' => $role, 'team_id' => $team?->id, 'job_title' => $title, 'phone' => $phone, 'is_active' => true,
        ]), function (User $u) use ($real) {
            $u->forceFill(['created_at' => $real->subDays(120), 'last_login_at' => $real->subHours(mt_rand(2, 30)),
                'alerts_seen_at' => $real->subDays(3)])->save();
        });

        return [
            'admin' => $make('Aarav Mehta', 'admin', null, 'Head of Sales Operations', '+91 55555 00001'),
            'manager' => $make('Meera Iyer', 'manager', $india, 'Sales Manager', '+91 55555 00002'),
            'sales' => [
                $make('Rohan Kapoor', 'sales', $india, 'Account Executive', '+91 55555 00003'),
                $make('Emily Carter', 'sales', $us, 'Account Executive', '+1 (212) 555-0104'),
                $make('Daniel Brooks', 'sales', $us, 'Sales Development Rep', '+1 (415) 555-0105'),
            ],
        ];
    }

    /** @return list<array{0: string, 1: bool}> status + whether the lead is in India (INR) */
    private function plan(): array
    {
        $plan = [];
        foreach (self::MIX as [$status, $count]) {
            for ($n = 0; $n < $count; $n++) {
                $plan[] = [$status, $n % 2 === 0];
            }
        }
        shuffle($plan);

        return $plan;
    }

    private function lead(int $i, string $target, bool $india, array $users, CarbonImmutable $real): void
    {
        $faker = $india ? $this->in : $this->us;
        $sales = $users['sales'];
        $owner = $target === 'New' && $i % 3 === 0 ? null // a few fresh leads wait for someone to pick them up
            : ($india ? [$sales[0], $users['manager']][$i % 4 === 0 ? 1 : 0] : $sales[1 + $i % 2]);
        $actor = $owner ?? $users['manager'];

        // Created at an IST working hour 1–75 days ago (the newest ones today).
        $created = $this->workHour($real->subDays($target === 'New' ? mt_rand(0, 6) : mt_rand(8, 75)), $real);
        Carbon::setTestNow($created);

        $first = $faker->firstName();
        $last = $faker->lastName();
        $company = self::COMPANIES[$i % count(self::COMPANIES)].($india ? ' India Pvt Ltd' : '');
        $currency = $india ? Currency::INR : Currency::USD;

        $lead = new Lead([
            'first_name' => $first, 'last_name' => $last, 'company' => $company,
            'job_title' => $faker->randomElement(['Founder', 'CEO', 'Head of Marketing', 'Operations Manager', 'IT Director', 'Procurement Lead', 'CFO', 'Product Manager']),
            'email' => $this->email($first, $last), 'phone' => $this->phone($india),
            'website' => 'https://'.Str::slug(self::COMPANIES[$i % count(self::COMPANIES)]).'.example',
            'city' => $india ? $faker->randomElement(['Mumbai', 'Bengaluru', 'Pune', 'Delhi', 'Hyderabad', 'Chennai'])
                : $faker->randomElement(['New York', 'Austin', 'Chicago', 'San Francisco', 'Seattle', 'Boston']),
            'country' => $india ? 'India' : 'United States',
            'description' => $faker->randomElement(['Looking to replace spreadsheets with a proper pipeline.', 'Asked for pricing for 20 seats.',
                'Expanding to two new cities next quarter.', 'Referred by an existing client.', null]),
            'lead_source_id' => LeadSource::query()->where('is_active', true)->inRandomOrder()->value('id'),
            'priority' => $faker->randomElement([...LeadPriority::cases(), null]),
            'currency' => $currency,
        ]);
        $lead->lead_status_id = LeadStatus::query()->where('name', 'New')->value('id');
        $lead->deal_value = $target === 'Invalid' || mt_rand(1, 100) <= 12 ? null
            : ($india ? mt_rand(4, 80) * 50_000 * 100 : mt_rand(6, 160) * 500 * 100);
        $lead->created_by = $actor->id;
        $lead->last_activity_at = $created;
        $lead->save();
        $lead->tags()->sync(Tag::query()->inRandomOrder()->limit(mt_rand(0, 2))->pluck('id'));
        app(ActivityLogger::class)->system($lead, $actor, 'created', "Lead created manually by {$actor->name}");
        Audit::log('lead.created', $lead, ['reference' => $lead->reference], $actor->id);
        if ($owner) {
            app(LeadAssigner::class)->assign($lead, $owner, $users['manager'], 'form');
        }

        // Walk the pipeline to the target status, a few days apart, logging what happened.
        $at = $created;
        foreach ($this->path($target, $i) as $step) {
            $at = $this->workHour($at->addDays(mt_rand(1, 6)), $real);
            Carbon::setTestNow($at);
            [$type, $text] = $this->activityFor($step);
            if ($type) {
                app(ActivityLogger::class)->log($lead, $actor, $type, $text, $this->outcomeFor($step), $at);
            }
            app(LeadStatusChanger::class)->change($lead, LeadStatus::query()->where('name', $step)->first(), $actor, $i % 5 === 0 ? 'kanban' : 'panel');
        }

        if (mt_rand(1, 100) <= 55) {
            $at = $this->workHour($at->addHours(mt_rand(1, 20)), $real);
            Carbon::setTestNow($at);
            $note = $lead->notes()->make(['body' => $faker->randomElement([
                'Prefers WhatsApp over email. Best time to call is after 4 pm.', 'Budget approval sits with the CFO; loop her in early.',
                'Currently on a competitor’s annual plan, renewal in March.', 'Asked us to share two case studies from their industry.',
                'Decision expected after their board meeting next month.', 'Very responsive. Wants a short pilot before committing.',
            ])]);
            $note->user_id = $actor->id;
            $note->save();
        }

        $this->followUps($lead, $target, $owner ?? $users['manager'], $created, $at, $real);
    }

    /** Statuses visited on the way to $target (after New). */
    private function path(string $target, int $i): array
    {
        if ($target === 'New') {
            return [];
        }
        if ($target === 'Invalid') {
            return ['Invalid'];
        }
        if (in_array($target, ['Lost', 'Not Interested'], true)) {
            $reached = array_slice(self::LADDER, 2, $i % 4); // lost somewhere along the way
            return [...($reached ? ['Attempted Contact', ...$reached] : ['Attempted Contact']), $target];
        }
        $end = $target === 'Won' ? count(self::LADDER) : array_search($target, self::LADDER, true) + 1;
        $steps = array_slice(self::LADDER, 1, $end - 1);
        // Real teams skip steps: drop Attempted Contact / Follow-Up Required / Meeting Booked now and then.
        $steps = array_values(array_filter($steps, fn ($s) => $s === $target || ! in_array($s, ['Attempted Contact', 'Follow-Up Required', 'Meeting Booked'], true) || mt_rand(0, 1)));

        return $target === 'Won' ? [...$steps, 'Won'] : $steps;
    }

    /** @return array{0: ?ActivityType, 1: string} */
    private function activityFor(string $step): array
    {
        return match ($step) {
            'Attempted Contact' => [ActivityType::Call, 'Called, no answer. Left a voicemail.'],
            'Contacted' => [ActivityType::Call, 'Intro call: walked through their current process and pain points.'],
            'Follow-Up Required' => [ActivityType::Email, 'Sent the recap email with next steps.'],
            'Qualified' => [ActivityType::Meeting, 'Discovery call: budget, decision maker and timeline confirmed.'],
            'Meeting Booked' => [ActivityType::Email, 'Sent calendar invite for the product demo.'],
            'Proposal Sent' => [ActivityType::Email, 'Shared the proposal and pricing.'],
            'Opportunity' => [ActivityType::Meeting, 'Negotiation call on scope and payment terms.'],
            'Won' => [ActivityType::Meeting, 'Contract signed. Kick-off scheduled.'],
            'Lost', 'Not Interested' => [ActivityType::Call, 'Closing call: they decided not to go ahead for now.'],
            default => [null, ''],
        };
    }

    private function outcomeFor(string $step): ?string
    {
        return match ($step) {
            'Attempted Contact' => 'No answer',
            'Qualified' => 'Qualified',
            'Won' => 'Closed won',
            'Lost' => 'Went with a competitor',
            'Not Interested' => 'No budget this year',
            default => null,
        };
    }

    /** Open leads get a pending follow-up (overdue, due today or upcoming); closed ones some history. */
    private function followUps(Lead $lead, string $target, User $who, CarbonImmutable $created, CarbonImmutable $last, CarbonImmutable $real): void
    {
        $service = app(FollowUps::class);
        $type = FollowUpType::cases()[$lead->id % count(FollowUpType::cases())];
        $endOfToday = $this->endOfToday;

        // A completed one in the past for most leads that got past New.
        if ($target !== 'New' && $target !== 'Invalid' && $lead->id % 3 !== 0) {
            Carbon::setTestNow($created->addHours(2));
            $done = $service->schedule($lead, $who, $who, FollowUpType::Call, $created->addDay(), 'First call after the enquiry.');
            Carbon::setTestNow(min($last, $created->addDay()->addHours(3)));
            $service->complete($done, $who, $lead->id % 2 ? 'Spoke to them; sent details.' : null);
        }

        if (in_array($target, ['Won', 'Lost', 'Not Interested', 'Invalid'], true)) {
            return;
        }

        $kind = $lead->id % 10; // 0–2 overdue, 3–4 due today, 5–9 upcoming
        $due = match (true) {
            $kind <= 2 => $real->subHours(mt_rand(3, 96)),
            $kind <= 4 => $real->addMinutes(mt_rand(20, max(21, (int) $real->diffInMinutes($endOfToday) - 10))),
            default => $this->workHour($real->addDays(mt_rand(1, 10)), $real->addDays(30)),
        };
        if ($kind >= 3 && $kind <= 4 && $due->gte($endOfToday)) {
            $due = $real->subMinutes(30); // too late in the IST day: it's overdue instead
        }
        $scheduledAt = $last->lt($due) ? $last : $due->subDay();
        Carbon::setTestNow(max($created, $scheduledAt));
        $service->schedule($lead, $who, $who, $type, $due, $kind <= 2 ? 'Promised to call back this week.' : null);
    }

    /** One finished import by the manager two days ago: 12 rows, 1 bad email, 1 duplicate. */
    private function import(User $manager, CarbonImmutable $real): void
    {
        Carbon::setTestNow($this->workHour($real->subDays(2), $real));
        $existing = Lead::query()->orderBy('id')->value('email');
        $rows = [['Name', 'Company', 'Email', 'Phone', 'City', 'Country', 'Deal Value', 'Currency', 'Source']];
        for ($n = 1; $n <= 10; $n++) {
            $first = $this->in->firstName();
            $last = $this->in->lastName();
            $rows[] = ["{$first} {$last}", self::COMPANIES[($n * 7) % count(self::COMPANIES)].' India Pvt Ltd', $this->email($first, $last),
                $this->phone(true), 'Pune', 'India', (string) (mt_rand(2, 20) * 50_000), 'INR', 'Event'];
        }
        $rows[] = ['Kiran Rao', 'Litware India Pvt Ltd', 'kiran.rao@@example', $this->phone(true), 'Pune', 'India', '', 'INR', 'Event'];
        $rows[] = ['Same Person', 'Contoso', $existing, '', 'Mumbai', 'India', '', 'INR', 'Event'];

        $path = tempnam(sys_get_temp_dir(), 'demo-import-');
        $fh = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fh, $row, escape: '');
        }
        fclose($fh);

        try {
            $import = new Import(['original_filename' => 'Pune trade show leads.csv', 'status' => 'reading']);
            $import->user_id = $manager->id;
            $import->save();
            $importer = app(LeadImporter::class);
            $importer->parse($import, $path, 'csv', 5000);
            Audit::log('import.started', $import, ['rows' => $import->total_rows], $manager->id);
            $importer->validate($import->fresh(), $manager);
            $importer->applyStrategy($import->fresh(), $manager, 'skip');
            while (! $importer->runChunk($import->fresh(), $manager)) {
            }
        } finally {
            @unlink($path); // like a real upload, the file never outlives the import
        }
    }

    /** A time between 9:30 and 18:30 IST on that day, never later than $max. */
    private function workHour(CarbonImmutable $day, CarbonImmutable $max): CarbonImmutable
    {
        $local = $day->setTimezone(Dates::zone())->setTime(mt_rand(9, 17), mt_rand(0, 59))->addMinutes(30);
        $at = $local->utc();

        return $at->gt($max) ? $max->subMinutes(mt_rand(5, 90)) : $at;
    }

    private function email(string $first, string $last): string
    {
        $base = Str::of("{$first}.{$last}")->ascii()->lower()->replaceMatches('/[^a-z.]/', '')->toString();
        $n = $this->emails[$base] = ($this->emails[$base] ?? 0) + 1;

        return $base.($n > 1 ? $n : '').'@example.com';
    }

    /** Clearly fake numbers, all different: US 555-01xx with varying area codes, India 55555 xxxxx. */
    private function phone(bool $india): string
    {
        $n = ++$this->phone;

        return $india
            ? sprintf('+91 55555 %05d', 10000 + $n)
            : sprintf('+1 (%d) 555-01%02d', [212, 312, 415, 512, 617, 206][intdiv($n, 100) % 6], $n % 100);
    }
}
