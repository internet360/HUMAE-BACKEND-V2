<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
use App\Services\AssignmentStageMachine;

it('allows the hiring happy path', function (): void {
    $path = [
        [AssignmentStage::Sourced, AssignmentStage::Presented],
        [AssignmentStage::Presented, AssignmentStage::Interviewing],
        [AssignmentStage::Interviewing, AssignmentStage::Finalist],
        [AssignmentStage::Finalist, AssignmentStage::Hired],
    ];

    foreach ($path as [$from, $to]) {
        expect(AssignmentStageMachine::canTransition($from, $to))->toBeTrue();
    }
});

it('allows rejection / withdrawal from any non-terminal stage', function (): void {
    $nonTerminals = [
        AssignmentStage::Sourced,
        AssignmentStage::Presented,
        AssignmentStage::Interviewing,
        AssignmentStage::Finalist,
    ];

    foreach ($nonTerminals as $stage) {
        expect(AssignmentStageMachine::canTransition($stage, AssignmentStage::Rejected))->toBeTrue();
        expect(AssignmentStageMachine::canTransition($stage, AssignmentStage::Withdrawn))->toBeTrue();
    }
});

it('does not allow skipping stages', function (): void {
    expect(AssignmentStageMachine::canTransition(AssignmentStage::Sourced, AssignmentStage::Interviewing))->toBeFalse();
    expect(AssignmentStageMachine::canTransition(AssignmentStage::Presented, AssignmentStage::Finalist))->toBeFalse();
    expect(AssignmentStageMachine::canTransition(AssignmentStage::Sourced, AssignmentStage::Hired))->toBeFalse();
});

it('does not allow exits from terminal stages', function (): void {
    expect(AssignmentStageMachine::allowedFrom(AssignmentStage::Hired))->toBe([]);
    expect(AssignmentStageMachine::allowedFrom(AssignmentStage::Rejected))->toBe([]);
    expect(AssignmentStageMachine::allowedFrom(AssignmentStage::Withdrawn))->toBe([]);
});

it('maps each stage to the correct timestamp field', function (): void {
    expect(AssignmentStageMachine::timestampField(AssignmentStage::Presented))->toBe(['presented_at' => 'now']);
    expect(AssignmentStageMachine::timestampField(AssignmentStage::Interviewing))->toBe(['interviewed_at' => 'now']);
    expect(AssignmentStageMachine::timestampField(AssignmentStage::Hired))->toBe(['hired_at' => 'now']);
    expect(AssignmentStageMachine::timestampField(AssignmentStage::Rejected))->toBe(['rejected_at' => 'now']);
    expect(AssignmentStageMachine::timestampField(AssignmentStage::Withdrawn))->toBe(['withdrawn_at' => 'now']);
});

it('returns no timestamp for Sourced (initial stage)', function (): void {
    expect(AssignmentStageMachine::timestampField(AssignmentStage::Sourced))->toBe([]);
});

it('exposes allowed values as strings', function (): void {
    $values = AssignmentStageMachine::allowedValuesFrom(AssignmentStage::Sourced);
    expect($values)->toContain('presented', 'rejected', 'withdrawn');
});
