<?php

declare(strict_types=1);

namespace App\Enums;

enum TutorialStatus: string
{
    case Completed = 'completed';
    case Skipped = 'skipped';
}
