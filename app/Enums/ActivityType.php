<?php

namespace App\Enums;

enum ActivityType: string
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case Sms = 'sms';
    case FollowUp = 'follow_up';
    case Note = 'note';
    case Other = 'other';
    case System = 'system';
}
