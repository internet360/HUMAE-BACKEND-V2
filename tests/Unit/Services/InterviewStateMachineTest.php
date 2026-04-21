<?php

declare(strict_types=1);

use App\Enums\InterviewState;
use App\Services\InterviewStateMachine;

it('allows propuesta → confirmada → realizada (happy path)', function (): void {
    expect(InterviewStateMachine::canTransition(InterviewState::Propuesta, InterviewState::Confirmada))->toBeTrue();
    expect(InterviewStateMachine::canTransition(InterviewState::Confirmada, InterviewState::Realizada))->toBeTrue();
});

it('allows reprogramación from propuesta and confirmada', function (): void {
    expect(InterviewStateMachine::canTransition(InterviewState::Propuesta, InterviewState::Reprogramada))->toBeTrue();
    expect(InterviewStateMachine::canTransition(InterviewState::Confirmada, InterviewState::Reprogramada))->toBeTrue();
});

it('allows no_asisto only from confirmada', function (): void {
    expect(InterviewStateMachine::canTransition(InterviewState::Confirmada, InterviewState::NoAsisto))->toBeTrue();
    expect(InterviewStateMachine::canTransition(InterviewState::Propuesta, InterviewState::NoAsisto))->toBeFalse();
});

it('reprogramada can go back to propuesta or be cancelled, nothing else', function (): void {
    expect(InterviewStateMachine::canTransition(InterviewState::Reprogramada, InterviewState::Propuesta))->toBeTrue();
    expect(InterviewStateMachine::canTransition(InterviewState::Reprogramada, InterviewState::Cancelada))->toBeTrue();
    expect(InterviewStateMachine::canTransition(InterviewState::Reprogramada, InterviewState::Confirmada))->toBeFalse();
    expect(InterviewStateMachine::canTransition(InterviewState::Reprogramada, InterviewState::Realizada))->toBeFalse();
});

it('does not allow exits from terminal states', function (): void {
    expect(InterviewStateMachine::allowedFrom(InterviewState::Realizada))->toBe([]);
    expect(InterviewStateMachine::allowedFrom(InterviewState::Cancelada))->toBe([]);
    expect(InterviewStateMachine::allowedFrom(InterviewState::NoAsisto))->toBe([]);
});

it('exposes allowed values as strings', function (): void {
    $values = InterviewStateMachine::allowedValuesFrom(InterviewState::Confirmada);
    expect($values)->toContain('realizada', 'reprogramada', 'cancelada', 'no_asisto');
});

it('includes every enum value as a graph key', function (): void {
    $graph = InterviewStateMachine::graph();

    foreach (InterviewState::cases() as $case) {
        expect(array_key_exists($case->value, $graph))->toBeTrue();
    }
});
