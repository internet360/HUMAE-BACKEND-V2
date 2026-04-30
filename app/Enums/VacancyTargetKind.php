<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de candidato que la vacante busca (PDF cosasfaltanteshumae, "Ajuste
 * en el módulo de vacantes privadas"). Se persiste en
 * vacancies.target_candidate_kind y se usa para el matching contra
 * candidate_profiles.candidate_kind.
 */
enum VacancyTargetKind: string
{
    case Employee = 'employee';
    case Intern = 'intern';
    case Any = 'any';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Empleado',
            self::Intern => 'Practicante',
            self::Any => 'Cualquiera',
        };
    }
}
