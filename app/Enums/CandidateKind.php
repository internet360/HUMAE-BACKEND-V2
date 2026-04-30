<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Categoría bajo la que se postula el candidato (PDF cosasfaltanteshumae,
 * punto 2). Se persiste en candidate_profiles.candidate_kind y se usa
 * para filtrar el directorio interno y para el matching contra vacantes.
 */
enum CandidateKind: string
{
    case Employee = 'employee';
    case Intern = 'intern';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Empleado',
            self::Intern => 'Practicante',
        };
    }
}
