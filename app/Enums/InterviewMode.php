<?php

declare(strict_types=1);

namespace App\Enums;

enum InterviewMode: string
{
    case Presencial = 'presencial';
    case Online = 'online';
    case Telefonica = 'telefonica';
}
