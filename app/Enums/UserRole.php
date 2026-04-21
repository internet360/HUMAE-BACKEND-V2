<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Candidate = 'candidate';
    case Recruiter = 'recruiter';
    case CompanyUser = 'company_user';
    case Admin = 'admin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
