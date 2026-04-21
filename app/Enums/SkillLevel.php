<?php

declare(strict_types=1);

namespace App\Enums;

enum SkillLevel: string
{
    case Basico = 'basico';
    case Intermedio = 'intermedio';
    case Avanzado = 'avanzado';
    case Experto = 'experto';
}
