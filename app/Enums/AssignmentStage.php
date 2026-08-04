<?php

declare(strict_types=1);

namespace App\Enums;

enum AssignmentStage: string
{
    case Sourced = 'sourced';
    case Presented = 'presented';
    case Interviewing = 'interviewing';
    case Finalist = 'finalist';
    case Hired = 'hired';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    /**
     * Etapas que un usuario de empresa cliente puede ver.
     *
     * HUMAE decide qué candidatos presenta. `sourced` es la lista interna del
     * reclutador y `rejected` son descartes previos a la presentación: ninguno
     * de los dos sale del equipo interno.
     *
     * `withdrawn` queda excluido porque la etapa actual no dice si el candidato
     * llegó a presentarse antes de retirarse. Ocultarlo puede hacer desaparecer
     * a alguien que la empresa ya conocía, pero mostrarlo filtraría a quien se
     * retiró siendo `sourced`. Se resuelve bien con un `presented_at` en la
     * asignación; hasta entonces se prioriza no filtrar.
     *
     * @return list<self>
     */
    public static function visibleToCompany(): array
    {
        return [
            self::Presented,
            self::Interviewing,
            self::Finalist,
            self::Hired,
        ];
    }

    /**
     * Valores string de las etapas visibles para empresa.
     *
     * @return list<string>
     */
    public static function visibleToCompanyValues(): array
    {
        return array_map(
            static fn (self $stage): string => $stage->value,
            self::visibleToCompany(),
        );
    }
}
