<?php

declare(strict_types=1);

namespace App\Enums;

enum InterviewState: string
{
    case Propuesta = 'propuesta';
    case Confirmada = 'confirmada';
    case Reprogramada = 'reprogramada';
    case Realizada = 'realizada';
    case Cancelada = 'cancelada';
    case NoAsisto = 'no_asisto';

    public function label(): string
    {
        return match ($this) {
            self::Propuesta => 'Propuesta',
            self::Confirmada => 'Confirmada',
            self::Reprogramada => 'Reprogramada',
            self::Realizada => 'Realizada',
            self::Cancelada => 'Cancelada',
            self::NoAsisto => 'No asistió',
        };
    }
}
