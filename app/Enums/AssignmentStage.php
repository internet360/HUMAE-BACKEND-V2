<?php

declare(strict_types=1);

namespace App\Enums;

enum AssignmentStage: string
{
    case Sourced = 'sourced';
    case Presented = 'presented';
    case Interviewing = 'interviewing';
    case Finalist = 'finalist';
    case Hired = 'hired';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
