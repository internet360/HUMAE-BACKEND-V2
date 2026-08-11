<?php

declare(strict_types=1);

namespace App\Enums;

enum QuestionType: string
{
    case MultipleChoice = 'multiple_choice';
    case Likert5 = 'likert_5';
    case Likert7 = 'likert_7';
    case Rank = 'rank';
    case TrueFalse = 'true_false';

    /**
     * Tipos que `PsychometricScoringService` sabe calificar.
     *
     * `Rank` queda AFUERA a propósito, y el caso se conserva en el enum sólo para
     * no romper datos futuros: el modelo de respuestas no puede ni representar un
     * ordenamiento —`psychometric_answers` guarda UN `option_id` por pregunta, sin
     * posición— así que un ítem de este tipo se guardaría bien y valdría 0 en
     * silencio, arrastrando hacia abajo la dimensión entera sin que nada avise.
     *
     * Implementarlo no es arreglar el scoring: necesita una columna de posición en
     * las respuestas y una definición de producto de cómo se puntúa un orden
     * (¿distancia al orden ideal? ¿puntos por posición?). Hasta que eso exista, no
     * se puede crear.
     *
     * El día que se implemente, se agrega acá y el formulario de admin lo ofrece
     * solo: la lista de tipos de la UI se deriva de este método.
     *
     * @return list<self>
     */
    public static function supported(): array
    {
        return [
            self::Likert5,
            self::Likert7,
            self::MultipleChoice,
            self::TrueFalse,
        ];
    }

    /**
     * @return list<string>
     */
    public static function supportedValues(): array
    {
        return array_map(fn (self $type): string => $type->value, self::supported());
    }
}
