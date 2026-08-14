<?php

declare(strict_types=1);

namespace App\Enums;

enum VacancyState: string
{
    case Borrador = 'borrador';

    /**
     * La empresa eligió candidatos y mandó la solicitud; HUMAE todavía no la
     * atiende. Es un estado del flujo del empleador y por eso no pasa por
     * `activa` ni `en_busqueda`: no hay fase de búsqueda que hacer cuando el
     * cliente ya señaló a quién quiere conocer.
     */
    case Solicitada = 'solicitada';
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
            self::Solicitada => 'Solicitada',
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
