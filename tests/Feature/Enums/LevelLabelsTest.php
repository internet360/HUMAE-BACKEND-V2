<?php

declare(strict_types=1);

use App\Enums\LanguageLevel;
use App\Enums\SkillLevel;

it('renders skill levels with accents and capitalized', function (): void {
    expect(SkillLevel::Basico->label())->toBe('Básico')
        ->and(SkillLevel::Intermedio->label())->toBe('Intermedio')
        ->and(SkillLevel::Avanzado->label())->toBe('Avanzado')
        ->and(SkillLevel::Experto->label())->toBe('Experto');
});

it('uppercases CEFR codes but not the word "nativo"', function (): void {
    expect(LanguageLevel::B1->label())->toBe('B1')
        ->and(LanguageLevel::C2->label())->toBe('C2')
        ->and(LanguageLevel::Nativo->label())->toBe('Nativo');
});

it('labels raw pivot values, which are not cast to the enum', function (): void {
    expect(SkillLevel::labelFor('basico'))->toBe('Básico')
        ->and(LanguageLevel::labelFor('c1'))->toBe('C1')
        ->and(LanguageLevel::labelFor('nativo'))->toBe('Nativo');
});

it('passes through values it does not know instead of blanking them', function (): void {
    // Un CV a medio imprimir es peor que uno con una etiqueta rara.
    expect(SkillLevel::labelFor('desconocido'))->toBe('desconocido')
        ->and(LanguageLevel::labelFor('xx'))->toBe('XX')
        ->and(SkillLevel::labelFor(null))->toBe('')
        ->and(LanguageLevel::labelFor(null))->toBe('');
});
