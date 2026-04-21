<?php

declare(strict_types=1);

namespace App\Enums;

enum QuestionType: string
{
    case MultipleChoice = 'multiple_choice';
    case Likert5 = 'likert_5';
    case Likert7 = 'likert_7';
    case Rank = 'rank';
    case TrueFalse = 'true_false';
}
