<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una solicitud de entrevistas enviada por la empresa cliente.
 *
 * Es el estado de la GESTIÓN, no el de cada candidato: una solicitud puede
 * quedar atendida con la mitad de los perfiles vetados. El desenlace por
 * persona vive en `InterviewRequestCandidateState`.
 */
enum InterviewRequestState: string
{
    /** Enviada por la empresa; HUMAE todavía no la toca. */
    case Pendiente = 'pendiente';

    /** Un reclutador la tomó y está resolviendo perfiles y horarios. */
    case EnGestion = 'en_gestion';

    /** Todos los perfiles tienen desenlace: aceptados o vetados. */
    case Atendida = 'atendida';

    /** La empresa se echó atrás antes de que HUMAE la resolviera. */
    case Cancelada = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::EnGestion => 'En gestión',
            self::Atendida => 'Atendida',
            self::Cancelada => 'Cancelada',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pendiente || $this === self::EnGestion;
    }
}
