<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VacancyState;

/**
 * Máquina de estados de Vacancy (ver ARCHITECTURE.md §7.2).
 *
 * borrador → activa → en_busqueda → con_candidatos_asignados →
 *   entrevistas_en_curso → finalista_seleccionado → cubierta
 *
 * Desde cualquier estado no-terminal se puede cancelar.
 */
class VacancyStateMachine
{
    /**
     * @return array<string, list<VacancyState>>
     */
    public static function graph(): array
    {
        return [
            VacancyState::Borrador->value => [
                VacancyState::Activa,
                VacancyState::Cancelada,
            ],
            VacancyState::Activa->value => [
                VacancyState::EnBusqueda,
                VacancyState::Cancelada,
            ],
            VacancyState::EnBusqueda->value => [
                VacancyState::ConCandidatosAsignados,
                VacancyState::Cancelada,
            ],
            VacancyState::ConCandidatosAsignados->value => [
                VacancyState::EntrevistasEnCurso,
                VacancyState::Cancelada,
            ],
            VacancyState::EntrevistasEnCurso->value => [
                VacancyState::FinalistaSeleccionado,
                VacancyState::Cancelada,
            ],
            VacancyState::FinalistaSeleccionado->value => [
                VacancyState::Cubierta,
                VacancyState::Cancelada,
            ],
            VacancyState::Cubierta->value => [],
            VacancyState::Cancelada->value => [],
        ];
    }

    /**
     * The policy ability that governs a transition into `$to`.
     *
     * "May edit my vacancy" and "may close my vacancy" are different rights
     * (ARCHITECTURE.md §6 grants the client company the second and denies it
     * the first), and collapsing both into `update` is what let a company drive
     * its own vacancy to `cubierta` through the staff endpoint — F-03. Naming
     * the ability after the purpose keeps them apart, and keeping the map here
     * means neither transition endpoint gets to invent its own whitelist.
     *
     * - `publish`  — borrador → activa. §6 "Aprobar / activar vacante".
     * - `close`    — → cubierta. §6 "Marcar vacante como cubierta".
     * - `cancel`   — → cancelada. Not covered by §6; keeps current behaviour.
     * - `advance`  — the internal pipeline states, which only HUMAE drives.
     */
    public static function abilityFor(VacancyState $to): string
    {
        return match ($to) {
            VacancyState::Activa => 'publish',
            VacancyState::Cubierta => 'close',
            VacancyState::Cancelada => 'cancel',
            default => 'advance',
        };
    }

    /**
     * @return list<VacancyState>
     */
    public static function allowedFrom(VacancyState $from): array
    {
        return self::graph()[$from->value] ?? [];
    }

    public static function canTransition(VacancyState $from, VacancyState $to): bool
    {
        foreach (self::allowedFrom($from) as $candidate) {
            if ($candidate === $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function allowedValuesFrom(VacancyState $from): array
    {
        return array_map(
            static fn (VacancyState $state) => $state->value,
            self::allowedFrom($from),
        );
    }
}
