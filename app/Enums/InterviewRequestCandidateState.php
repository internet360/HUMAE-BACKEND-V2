<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Desenlace de cada perfil dentro de una solicitud.
 *
 * `Vetado` es por perfil y no por solicitud a propósito: si HUMAE no puede
 * presentar a una persona —ya está en proceso con otra empresa, no acepta el
 * rango, no da el perfil— se cae esa y la solicitud sigue viva con el resto.
 * Tumbar la solicitud entera obligaría a la empresa a rehacer el trabajo por
 * una decisión que no es suya.
 */
enum InterviewRequestCandidateState: string
{
    case Pendiente = 'pendiente';

    /** HUMAE lo presenta: nace su asignación en el pipeline de la vacante. */
    case Aceptado = 'aceptado';

    /** HUMAE no lo presenta. Lleva motivo, que la empresa sí lee. */
    case Vetado = 'vetado';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Aceptado => 'Aceptado',
            self::Vetado => 'Vetado',
        };
    }

    public function isResolved(): bool
    {
        return $this !== self::Pendiente;
    }
}
