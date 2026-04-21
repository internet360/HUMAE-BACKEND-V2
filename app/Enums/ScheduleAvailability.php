<?php

declare(strict_types=1);

namespace App\Enums;

enum ScheduleAvailability: string
{
    case TiempoCompleto = 'tiempo_completo';
    case MedioTiempo = 'medio_tiempo';
    case PorHoras = 'por_horas';
    case Remoto = 'remoto';
}
