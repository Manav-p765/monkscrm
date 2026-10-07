<?php

namespace Database\Seeders;

use App\Models\LeadStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Default pipeline statuses. Safe to run on an existing database:
 * - statuses are matched by name ignoring case, so nothing is duplicated;
 * - existing statuses keep their name, colour, order, type and active flag (admins may have changed
 *   them); only an empty description or milestone is filled in;
 * - a missing default status is inserted after its predecessor (and after anything the admin placed
 *   within 10 of it), shifting later statuses down,
 *   so the admin's order is preserved.
 */
class LeadStatusSeeder extends Seeder
{
    /** [name, palette colour, type, milestone, description] in pipeline order. */
    public const DEFAULTS = [
        ['New', 'blue', 'open', null, 'Freshly created lead, awaiting first outreach'],
        ['Attempted Contact', 'amber', 'open', null, 'Left voicemail or email sent without response yet'],
        ['Contacted', 'indigo', 'open', 'contacted', 'Initial contact made, discussion opened'],
        ['Follow-Up Required', 'purple', 'open', null, 'Action items pending, scheduled follow-up needed'],
        ['Qualified', 'teal', 'open', 'qualified', 'Budget, authority, need, and timeline verified'],
        ['Meeting Booked', 'blue', 'open', 'meeting_booked', 'Meeting or demo scheduled with the decision maker'],
        ['Proposal Sent', 'purple', 'open', 'proposal_sent', 'Proposal or quote shared, awaiting a decision'],
        ['Opportunity', 'teal', 'open', null, 'Deal in late evaluation or negotiation'],
        ['Won', 'emerald', 'won', 'won', 'Contract signed and closed successfully'],
        ['Lost', 'rose', 'lost', 'lost', 'Closed lost to a competitor or deferred budget'],
        ['Not Interested', 'slate', 'lost', 'lost', 'Lead explicitly declined engagement'],
        ['Invalid', 'slate', 'lost', 'lost', 'Wrong contact info, spam or duplicate entry'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $previous = null;

            foreach (self::DEFAULTS as [$name, $colour, $type, $milestone, $description]) {
                $status = LeadStatus::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

                if ($status) {
                    $status->description ??= $description;
                    $status->milestone ??= $milestone;
                    $status->save();
                } else {
                    $status = LeadStatus::create([
                        'name' => $name,
                        'color' => config("crm.palette.{$colour}.hex"),
                        'type' => $type,
                        'milestone' => $milestone,
                        'description' => $description,
                        'sort_order' => $this->slotAfter($previous),
                        'is_active' => true,
                    ]);
                }

                $previous = $status;
            }
        });
    }

    /** Sort order right after $previous (or first), moving every later status down by 10. */
    private function slotAfter(?LeadStatus $previous): int
    {
        if (! LeadStatus::query()->exists()) {
            return 10;
        }

        $position = ($previous?->sort_order ?? 0) + 10;
        LeadStatus::query()->where('sort_order', '>=', $position)->increment('sort_order', 10);

        return $position;
    }
}
