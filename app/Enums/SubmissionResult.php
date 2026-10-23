<?php

namespace App\Enums;

enum SubmissionResult: string
{
    case Created = 'created';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';
    case Spam = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'New lead',
            self::Duplicate => 'Existing lead',
            self::Rejected => 'Rejected',
            self::Spam => 'Spam',
        };
    }

    /** <x-badge> tone. */
    public function tone(): string
    {
        return match ($this) {
            self::Created => 'green',
            self::Duplicate => 'blue',
            self::Rejected => 'red',
            self::Spam => 'neutral',
        };
    }
}
