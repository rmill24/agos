<?php

namespace App\Enums;

enum GlobalRole: string
{
    case SystemAdministrator = 'system_administrator';

    public function label(): string
    {
        return 'System Administrator';
    }
}
