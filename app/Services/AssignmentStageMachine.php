<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentStage;

/**
 * FSM de asignaciones de candidatos a vacantes.
 *
 * sourced → presented → interviewing → finalist → hired
 *                              ↓
 *                            rejected / withdrawn (terminales)
 */
class AssignmentStageMachine
{
    /**
     * @return array<string, list<AssignmentStage>>
     */
    public static function graph(): array
    {
        return [
            AssignmentStage::Sourced->value => [
                AssignmentStage::Presented,
                AssignmentStage::Rejected,
                AssignmentStage::Withdrawn,
            ],
            AssignmentStage::Presented->value => [
                AssignmentStage::Interviewing,
                AssignmentStage::Rejected,
                AssignmentStage::Withdrawn,
            ],
            AssignmentStage::Interviewing->value => [
                AssignmentStage::Finalist,
                AssignmentStage::Rejected,
                AssignmentStage::Withdrawn,
            ],
            AssignmentStage::Finalist->value => [
                AssignmentStage::Hired,
                AssignmentStage::Rejected,
                AssignmentStage::Withdrawn,
            ],
            AssignmentStage::Hired->value => [],
            AssignmentStage::Rejected->value => [],
            AssignmentStage::Withdrawn->value => [],
        ];
    }

    /**
     * @return list<AssignmentStage>
     */
    public static function allowedFrom(AssignmentStage $from): array
    {
        return self::graph()[$from->value] ?? [];
    }

    public static function canTransition(AssignmentStage $from, AssignmentStage $to): bool
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
    public static function allowedValuesFrom(AssignmentStage $from): array
    {
        return array_map(
            static fn (AssignmentStage $s) => $s->value,
            self::allowedFrom($from),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public static function timestampField(AssignmentStage $stage): array
    {
        return match ($stage) {
            AssignmentStage::Presented => ['presented_at' => 'now'],
            AssignmentStage::Interviewing => ['interviewed_at' => 'now'],
            AssignmentStage::Hired => ['hired_at' => 'now'],
            AssignmentStage::Rejected => ['rejected_at' => 'now'],
            AssignmentStage::Withdrawn => ['withdrawn_at' => 'now'],
            default => [],
        };
    }
}
