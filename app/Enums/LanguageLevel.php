<?php

declare(strict_types=1);

namespace App\Enums;

enum LanguageLevel: string
{
    case A1 = 'a1';
    case A2 = 'a2';
    case B1 = 'b1';
    case B2 = 'b2';
    case C1 = 'c1';
    case C2 = 'c2';
    case Nativo = 'nativo';

    /**
     * Etiqueta para mostrar. Los códigos MCER van en mayúsculas; "Nativo" no,
     * que es una palabra y no una sigla.
     */
    public function label(): string
    {
        return match ($this) {
            self::Nativo => 'Nativo',
            default => strtoupper($this->value),
        };
    }

    /**
     * Igual que label(), pero tolera el valor crudo del pivote, que no está
     * casteado al enum.
     */
    public static function labelFor(?string $value): string
    {
        $value = (string) $value;

        return self::tryFrom($value)?->label() ?? strtoupper($value);
    }
}
