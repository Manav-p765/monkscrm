<?php

namespace App\Enums;

enum LeadPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * <x-badge> tone. Only High and Urgent get a colour; Low and Medium stay grey.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Urgent => 'red',
            self::High => 'amber',
            default => 'neutral',
        };
    }
}
