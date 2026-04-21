<?php

declare(strict_types=1);

namespace App\Enums;

enum CompanyMemberRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Viewer = 'viewer';
}
