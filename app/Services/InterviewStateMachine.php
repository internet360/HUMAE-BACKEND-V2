<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InterviewState;

/**
 * FSM de entrevistas.
 *
 * propuesta → confirmada → realizada
 *      ↓          ↓
 *   reprogramada (vuelve a propuesta)
 *      ↓          ↓
 *    cancelada / no_asisto (terminales)
 */
class InterviewStateMachine
{
    /**
     * @return array<string, list<InterviewState>>
     */
    public static function graph(): array
    {
        return [
            InterviewState::Propuesta->value => [
                InterviewState::Confirmada,
                InterviewState::Reprogramada,
                InterviewState::Cancelada,
            ],
            InterviewState::Confirmada->value => [
                InterviewState::Realizada,
                InterviewState::Reprogramada,
                InterviewState::Cancelada,
                InterviewState::NoAsisto,
            ],
            InterviewState::Reprogramada->value => [
                InterviewState::Propuesta,
                InterviewState::Cancelada,
            ],
            InterviewState::Realizada->value => [],
            InterviewState::Cancelada->value => [],
            InterviewState::NoAsisto->value => [],
        ];
    }

    /**
     * @return list<InterviewState>
     */
    public static function allowedFrom(InterviewState $from): array
    {
        return self::graph()[$from->value] ?? [];
    }

    public static function canTransition(InterviewState $from, InterviewState $to): bool
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
    public static function allowedValuesFrom(InterviewState $from): array
    {
        return array_map(
            static fn (InterviewState $s) => $s->value,
            self::allowedFrom($from),
        );
    }
}
