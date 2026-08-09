<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Plantillas disponibles para renderizar el CV del candidato.
 *
 * Agregar una plantilla nueva es sumar un case acá y crear el Blade que
 * devuelve view(). El catálogo se sirve por API, así que el frontend no
 * necesita cambios para mostrarla.
 */
enum CvTemplate: string
{
    case Classic = 'classic';
    case Modern = 'modern';
    case Compact = 'compact';

    public static function default(): self
    {
        return self::Classic;
    }

    public function label(): string
    {
        return match ($this) {
            self::Classic => 'Clásica',
            self::Modern => 'Moderna',
            self::Compact => 'Compacta',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Classic => 'Encabezado horizontal y secciones a todo el ancho. Sobria y neutral.',
            self::Modern => 'Barra lateral en color de marca con contacto, habilidades e idiomas.',
            self::Compact => 'Una sola columna y tipografía densa. Rinde más si tu experiencia es extensa.',
        };
    }

    /**
     * Vista Blade que renderiza esta plantilla.
     */
    public function view(): string
    {
        return 'pdf.cv.'.$this->value;
    }
}
