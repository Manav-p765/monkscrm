<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Sales = 'sales';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
