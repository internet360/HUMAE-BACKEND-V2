<?php

declare(strict_types=1);

use App\Enums\VacancyState;
use App\Services\VacancyStateMachine;

it('allows borrador → activa', function (): void {
    expect(VacancyStateMachine::canTransition(VacancyState::Borrador, VacancyState::Activa))->toBeTrue();
});

it('allows any non-terminal state to transition to cancelada', function (): void {
    $nonTerminals = [
        VacancyState::Borrador,
        VacancyState::Activa,
        VacancyState::EnBusqueda,
        VacancyState::ConCandidatosAsignados,
        VacancyState::EntrevistasEnCurso,
        VacancyState::FinalistaSeleccionado,
    ];

    foreach ($nonTerminals as $from) {
        expect(VacancyStateMachine::canTransition($from, VacancyState::Cancelada))
            ->toBeTrue("$from->value debería poder cancelarse");
    }
});

it('does not allow skipping states', function (): void {
    expect(VacancyStateMachine::canTransition(VacancyState::Borrador, VacancyState::EnBusqueda))->toBeFalse();
    expect(VacancyStateMachine::canTransition(VacancyState::Activa, VacancyState::Cubierta))->toBeFalse();
    expect(VacancyStateMachine::canTransition(VacancyState::Borrador, VacancyState::Cubierta))->toBeFalse();
});

it('does not allow transitions out of terminal states', function (): void {
    expect(VacancyStateMachine::allowedFrom(VacancyState::Cubierta))->toBe([]);
    expect(VacancyStateMachine::allowedFrom(VacancyState::Cancelada))->toBe([]);
});

it('allows the full happy path borrador → cubierta', function (): void {
    $path = [
        [VacancyState::Borrador, VacancyState::Activa],
        [VacancyState::Activa, VacancyState::EnBusqueda],
        [VacancyState::EnBusqueda, VacancyState::ConCandidatosAsignados],
        [VacancyState::ConCandidatosAsignados, VacancyState::EntrevistasEnCurso],
        [VacancyState::EntrevistasEnCurso, VacancyState::FinalistaSeleccionado],
        [VacancyState::FinalistaSeleccionado, VacancyState::Cubierta],
    ];

    foreach ($path as [$from, $to]) {
        expect(VacancyStateMachine::canTransition($from, $to))
            ->toBeTrue("$from->value → $to->value debería permitirse");
    }
});

it('exposes allowed transitions as string values', function (): void {
    $values = VacancyStateMachine::allowedValuesFrom(VacancyState::Borrador);

    expect($values)->toBeArray()
        ->and($values)->toContain('activa', 'cancelada');
});

it('includes every enum value as a graph key', function (): void {
    $graph = VacancyStateMachine::graph();

    foreach (VacancyState::cases() as $case) {
        expect(array_key_exists($case->value, $graph))->toBeTrue("$case->value debería estar en el grafo");
    }
});
