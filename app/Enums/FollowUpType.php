<?php

namespace App\Enums;

/** Follow-up action types (prototype: Call, Send Email, Product Demo, Check-in, Send Proposal + Meeting, Other). */
enum FollowUpType: string
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case Demo = 'demo';
    case CheckIn = 'check_in';
    case Proposal = 'proposal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::Email => 'Send Email',
            self::Meeting => 'Meeting',
            self::Demo => 'Product Demo',
            self::CheckIn => 'Check-in',
            self::Proposal => 'Send Proposal',
            self::Other => 'Other',
        };
    }
}
