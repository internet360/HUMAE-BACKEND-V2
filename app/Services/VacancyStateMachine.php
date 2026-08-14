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
                VacancyState::Solicitada,
                VacancyState::Cancelada,
            ],
            // `solicitada` sale a `con_candidatos_asignados` cuando HUMAE
            // acepta al menos un perfil de la solicitud. La salida a
            // `en_busqueda` es el desagüe: si HUMAE veta a todos los que la
            // empresa señaló, la vacante no puede quedarse atascada esperando
            // candidatos que ya no van a llegar por ese camino.
            VacancyState::Solicitada->value => [
                VacancyState::ConCandidatosAsignados,
                VacancyState::EnBusqueda,
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
     * - `submit`   — → solicitada. The employer flow: the client picked whom it
     *   wants to meet and files the request. This is the one transition a
     *   client company DRIVES rather than proposes, so it gets its own ability
     *   instead of borrowing `advance` — which is HUMAE's. Folding it in is the
     *   exact shape of F-03, where one ability covering two rights let a
     *   company drive its own vacancy to `cubierta`.
     * - `advance`  — the internal pipeline states, which only HUMAE drives.
     */
    public static function abilityFor(VacancyState $to): string
    {
        return match ($to) {
            VacancyState::Activa => 'publish',
            VacancyState::Solicitada => 'submit',
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
