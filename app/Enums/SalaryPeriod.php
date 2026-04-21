<?php

declare(strict_types=1);

namespace App\Enums;

enum SalaryPeriod: string
{
    case Hora = 'hora';
    case Dia = 'dia';
    case Semana = 'semana';
    case Quincena = 'quincena';
    case Mes = 'mes';
    case Anio = 'anio';
}
