<?php

namespace App\Enums;

/**
 * Pipeline milestone a status can represent. The first time a lead enters a status with a
 * milestone, the matching timestamp column on the lead is stamped (once, never overwritten).
 */
enum LeadMilestone: string
{
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case MeetingBooked = 'meeting_booked';
    case ProposalSent = 'proposal_sent';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Contacted => 'Contacted',
            self::Qualified => 'Qualified',
            self::MeetingBooked => 'Meeting booked',
            self::ProposalSent => 'Proposal sent',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /** The leads column stamped when a lead first reaches this milestone. */
    public function column(): string
    {
        return match ($this) {
            self::Contacted => 'first_contacted_at',
            self::Qualified => 'qualified_at',
            self::MeetingBooked => 'meeting_booked_at',
            self::ProposalSent => 'proposal_sent_at',
            self::Won => 'won_at',
            self::Lost => 'lost_at',
        };
    }
}
