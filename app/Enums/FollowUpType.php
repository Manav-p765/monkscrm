<?php

namespace App\Enums;

enum FollowUpType: string
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case Other = 'other';
}
