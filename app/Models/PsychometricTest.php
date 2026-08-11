<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PsychometricTestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PsychometricTest extends Model
{
    /** @use HasFactory<PsychometricTestFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'time_limit_minutes',
        'passing_score',
        'max_attempts',
        'instructions',
        'sort_order',
        'is_active',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'time_limit_minutes' => 'integer',
            'passing_score' => 'integer',
            'max_attempts' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_required' => 'boolean',
        ];
    }

    /** @return HasMany<PsychometricTestSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(PsychometricTestSection::class);
    }

    /** @return HasMany<PsychometricQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(PsychometricQuestion::class);
    }

    /** @return HasMany<PsychometricAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PsychometricAttempt::class);
    }

    /**
     * Mapa `question_id => [option_id, ...]` de toda la prueba.
     *
     * Vive en el modelo porque lo necesitan dos capas que no deben divergir:
     * `SavePsychometricAnswersRequest` para responder 422 con el campo exacto, y
     * `PsychometricTestService::saveAnswers()` para sostener el invariante aunque
     * lo llame alguien que no pasó por el Form Request. Si la definición de "qué
     * opción pertenece a qué pregunta" viviera duplicada en ambas, alcanzaría con
     * que una se quedara vieja para reabrir el agujero.
     *
     * Una sola pasada: validar par por par haría dos consultas por ítem.
     *
     * @return array<int, list<int>>
     */
    public function optionIdsByQuestion(): array
    {
        $questions = $this->questions()
            ->with(['options:id,psychometric_question_id'])
            ->get(['id']);

        $map = [];

        foreach ($questions as $question) {
            // `array_values` no es cosmético: `pluck()->all()` conserva las
            // llaves de la colección, así que PHPStan nivel 8 lo tipa como
            // `array<int>` y no como `list<int>`.
            $map[(int) $question->id] = array_values(
                $question->options
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all()
            );
        }

        return $map;
    }
}
