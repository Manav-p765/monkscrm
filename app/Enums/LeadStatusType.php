<?php

namespace App\Enums;

enum LeadStatusType: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';
}
