<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PsychometricQuestion;
use App\Models\PsychometricQuestionOption;
use App\Models\PsychometricTest;
use App\Models\PsychometricTestSection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Autoría de pruebas psicométricas (lado admin).
 *
 * ── La decisión de diseño que sostiene el módulo ─────────────────────────────
 *
 * Un resultado psicométrico sólo se puede interpretar contra la estructura con
 * la que se midió. Si un admin edita el puntaje de una opción, invierte un ítem
 * o borra una pregunta DESPUÉS de que alguien rindió, los `dimension_scores` ya
 * guardados pasan a ser números sin origen: nadie puede reconstruir de dónde
 * salieron, y comparar dos candidatos deja de ser válido.
 *
 * Por eso una prueba EN USO —con al menos un intento— tiene la estructura
 * congelada. No es una limitación con la que haya que convivir: el camino para
 * cambiarla es `duplicate()`, que produce una versión nueva y editable y deja la
 * anterior intacta con sus resultados. Congelar + versionar es lo que hace que
 * esto escale; permitir la edición libre habría sido más cómodo hoy y habría
 * corrompido el histórico de forma irreversible.
 *
 * Lo cosmético y lo operativo (nombre, instrucciones, orden, activa, intentos,
 * tiempo) se sigue editando siempre: no cambia cómo se calificó nada.
 */
class PsychometricAuthoringService
{
    /**
     * Campos de la prueba que dejan de ser editables cuando ya hay intentos.
     *
     * `passing_score` porque redefine `passed` de resultados ya emitidos.
     * `code` porque es la identidad con la que se referencia la prueba desde
     * afuera (seeders, reportes, integraciones).
     *
     * @var list<string>
     */
    private const LOCKED_WHEN_IN_USE = ['code', 'passing_score'];

    /**
     * ¿Alguien rindió esta prueba?
     *
     * Cuenta cualquier intento, incluso `in_progress`: alguien que está
     * respondiendo en este momento no puede ver cambiar el cuestionario bajo los
     * pies.
     */
    public function isInUse(PsychometricTest $test): bool
    {
        return $test->attempts()->exists();
    }

    /**
     * @throws RuntimeException si la prueba ya tiene intentos
     */
    public function assertStructureMutable(PsychometricTest $test): void
    {
        if ($this->isInUse($test)) {
            throw new RuntimeException(
                'La prueba ya tiene intentos: su estructura está congelada para no '
                .'invalidar resultados emitidos. Duplícala para crear una versión nueva.',
            );
        }
    }

    /**
     * Filtra de la entrada los campos bloqueados cuando la prueba está en uso.
     *
     * Devuelve los datos aplicables y la lista de lo que se rechazó, para que el
     * controller pueda decirlo en lugar de ignorarlo en silencio.
     *
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, rejected: list<string>}
     */
    public function filterLockedFields(PsychometricTest $test, array $data): array
    {
        if (! $this->isInUse($test)) {
            return ['data' => $data, 'rejected' => []];
        }

        $rejected = [];

        foreach (self::LOCKED_WHEN_IN_USE as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            // Reenviar el mismo valor no es un cambio: no se reporta. El PATCH
            // de un formulario manda todos los campos, así que sin esto un
            // cambio de nombre reportaría `code` y `passing_score` como
            // rechazados sin que el admin hubiera intentado tocarlos.
            //
            // Se comparan como texto porque los lados tienen tipos distintos:
            // la entrada validada llega cruda del JSON ("10") y el atributo del
            // modelo ya viene casteado (int 10). Un `===` directo los daría por
            // diferentes.
            $incoming = $data[$field] ?? null;
            $current = $test->{$field};

            if ((string) ($incoming ?? '') === (string) ($current ?? '')) {
                unset($data[$field]);

                continue;
            }

            unset($data[$field]);
            $rejected[] = $field;
        }

        return ['data' => $data, 'rejected' => $rejected];
    }

    /**
     * Copia profunda de una prueba: secciones, preguntas y opciones.
     *
     * Es la salida al congelamiento. La copia nace inactiva y sin intentos, así
     * que es completamente editable; la original conserva sus resultados. El
     * admin publica la nueva y desactiva la vieja cuando quiera.
     *
     * @throws RuntimeException si el código nuevo ya existe
     */
    public function duplicate(PsychometricTest $test, string $newCode, ?string $newName = null): PsychometricTest
    {
        if (PsychometricTest::where('code', $newCode)->exists()) {
            throw new RuntimeException("Ya existe una prueba con el código '{$newCode}'.");
        }

        return DB::transaction(function () use ($test, $newCode, $newName): PsychometricTest {
            $copy = $test->replicate(['created_at', 'updated_at']);
            $copy->code = $newCode;
            $copy->name = $newName ?? $test->name.' (copia)';
            // Nace apagada: publicar una versión nueva es una decisión aparte de
            // crearla.
            $copy->is_active = false;
            $copy->save();

            // Las preguntas apuntan a secciones; hay que reasignar los ids
            // nuevos o quedarían colgadas de la prueba original.
            $sectionMap = [];

            // Las FK se reasignan con `forceFill` y no por propiedad: las
            // columnas unsigned están tipadas `int<0, max>` en los modelos y una
            // asignación directa de `int` no pasa PHPStan nivel 8.
            foreach ($test->sections as $section) {
                $newSection = $section->replicate(['created_at', 'updated_at']);
                $newSection->forceFill(['psychometric_test_id' => $copy->id])->save();

                $sectionMap[$section->id] = $newSection->id;
            }

            $questions = $test->questions()->with('options')->get();

            foreach ($questions as $question) {
                $newQuestion = $question->replicate(['created_at', 'updated_at']);
                $newQuestion->forceFill([
                    'psychometric_test_id' => $copy->id,
                    'psychometric_test_section_id' => $question->psychometric_test_section_id !== null
                        ? ($sectionMap[$question->psychometric_test_section_id] ?? null)
                        : null,
                ])->save();

                foreach ($question->options as $option) {
                    $newOption = $option->replicate(['created_at', 'updated_at']);
                    $newOption->forceFill(['psychometric_question_id' => $newQuestion->id])->save();
                }
            }

            return $copy->fresh(['sections', 'questions.options']) ?? $copy;
        });
    }

    /**
     * @throws RuntimeException si la prueba tiene intentos
     */
    public function deleteTest(PsychometricTest $test): void
    {
        if ($this->isInUse($test)) {
            throw new RuntimeException(
                'No se puede borrar: la prueba tiene intentos de candidatos. '
                .'Desactívala en su lugar.',
            );
        }

        // Secciones, preguntas y opciones caen por cascadeOnDelete.
        $test->delete();
    }

    /**
     * Resuelve la prueba dueña de una sección / pregunta / opción.
     *
     * Los endpoints de sección, pregunta y opción reciben el hijo pero la regla
     * de congelamiento vive en la prueba, así que siempre hay que subir.
     */
    public function testOf(PsychometricTestSection|PsychometricQuestion|PsychometricQuestionOption $node): ?PsychometricTest
    {
        return match (true) {
            $node instanceof PsychometricQuestionOption => $node->question?->test,
            default => $node->test,
        };
    }
}
