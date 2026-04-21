<?php

declare(strict_types=1);

namespace App\Enums;

enum AvailabilityType: string
{
    case Inmediata = 'inmediata';
    case DosSemanas = 'dos_semanas';
    case UnMes = 'un_mes';
    case ANegociar = 'a_negociar';
}
