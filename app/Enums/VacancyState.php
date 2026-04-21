<?php

declare(strict_types=1);

namespace App\Enums;

enum VacancyState: string
{
    case Borrador = 'borrador';
    case Activa = 'activa';
    case EnBusqueda = 'en_busqueda';
    case ConCandidatosAsignados = 'con_candidatos_asignados';
    case EntrevistasEnCurso = 'entrevistas_en_curso';
    case FinalistaSeleccionado = 'finalista_seleccionado';
    case Cubierta = 'cubierta';
    case Cancelada = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Activa => 'Activa',
            self::EnBusqueda => 'En búsqueda',
            self::ConCandidatosAsignados => 'Con candidatos asignados',
            self::EntrevistasEnCurso => 'Entrevistas en curso',
            self::FinalistaSeleccionado => 'Finalista seleccionado',
            self::Cubierta => 'Cubierta',
            self::Cancelada => 'Cancelada',
        };
    }
}
