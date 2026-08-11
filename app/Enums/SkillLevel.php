<?php

declare(strict_types=1);

namespace App\Enums;

enum SkillLevel: string
{
    case Basico = 'basico';
    case Intermedio = 'intermedio';
    case Avanzado = 'avanzado';
    case Experto = 'experto';

    /**
     * Etiqueta para mostrar. El valor guardado va sin acentos y en minúscula;
     * imprimirlo crudo en un CV se lee como una llave de base de datos.
     */
    public function label(): string
    {
        return match ($this) {
            self::Basico => 'Básico',
            self::Intermedio => 'Intermedio',
            self::Avanzado => 'Avanzado',
            self::Experto => 'Experto',
        };
    }

    /**
     * Igual que label(), pero tolera el valor crudo del pivote, que no está
     * casteado al enum.
     */
    public static function labelFor(?string $value): string
    {
        $value = (string) $value;

        return self::tryFrom($value)?->label() ?? $value;
    }
}
