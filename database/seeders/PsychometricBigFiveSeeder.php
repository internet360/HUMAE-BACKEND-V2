<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QuestionType;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricQuestionOption;
use App\Models\PsychometricTest;
use App\Models\PsychometricTestSection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PsychometricBigFiveSeeder extends Seeder
{
    /**
     * Cinco dimensiones (Big Five). 5 ítems por dimensión = 25 ítems.
     * Basado en Mini-IPIP (Donnellan et al. 2006, dominio público) + ítems
     * adicionales del banco IPIP en español (Morizot 2014).
     *
     * Cada ítem tiene `reverse` que indica si es invertido (p.ej. "No me
     * gusta llamar la atención" para Extraversión).
     *
     * @var array<string, list<array{text: string, reverse: bool}>>
     */
    private const ITEMS = [
        'extraversion' => [
            ['text' => 'Soy el alma de la fiesta.', 'reverse' => false],
            ['text' => 'Hablo con muchas personas distintas en las reuniones sociales.', 'reverse' => false],
            ['text' => 'No hablo mucho.', 'reverse' => true],
            ['text' => 'Prefiero quedarme en segundo plano.', 'reverse' => true],
            ['text' => 'Me siento cómodo/a siendo el centro de atención.', 'reverse' => false],
        ],
        'amabilidad' => [
            ['text' => 'Simpatizo con los sentimientos de los demás.', 'reverse' => false],
            ['text' => 'Me tomo tiempo para apoyar a los demás.', 'reverse' => false],
            ['text' => 'No me interesan los problemas de otros.', 'reverse' => true],
            ['text' => 'Siento poco por los demás.', 'reverse' => true],
            ['text' => 'Confío en las intenciones de las personas.', 'reverse' => false],
        ],
        'responsabilidad' => [
            ['text' => 'Termino las tareas a tiempo.', 'reverse' => false],
            ['text' => 'Me gusta tener las cosas en orden.', 'reverse' => false],
            ['text' => 'Dejo mis cosas tiradas por ahí.', 'reverse' => true],
            ['text' => 'Olvido volver a poner las cosas en su lugar.', 'reverse' => true],
            ['text' => 'Sigo un horario establecido.', 'reverse' => false],
        ],
        'neuroticismo' => [
            ['text' => 'Tengo cambios de humor frecuentes.', 'reverse' => false],
            ['text' => 'Me preocupo por cosas que no importan.', 'reverse' => false],
            ['text' => 'Me estreso con facilidad.', 'reverse' => false],
            ['text' => 'Raramente me siento triste.', 'reverse' => true],
            ['text' => 'Mantengo la calma bajo presión.', 'reverse' => true],
        ],
        'apertura' => [
            ['text' => 'Tengo una imaginación vívida.', 'reverse' => false],
            ['text' => 'Me interesan las ideas abstractas.', 'reverse' => false],
            ['text' => 'No tengo interés por las artes.', 'reverse' => true],
            ['text' => 'Tengo dificultad para entender ideas abstractas.', 'reverse' => true],
            ['text' => 'Uso palabras difíciles para expresar ideas complejas.', 'reverse' => false],
        ],
    ];

    /**
     * Escala Likert 5 puntos.
     *
     * @var list<array{label: string, score: int}>
     */
    private const LIKERT5 = [
        ['label' => 'Muy inexacto', 'score' => 1],
        ['label' => 'Inexacto', 'score' => 2],
        ['label' => 'Neutral', 'score' => 3],
        ['label' => 'Exacto', 'score' => 4],
        ['label' => 'Muy exacto', 'score' => 5],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $test = PsychometricTest::updateOrCreate(
                ['code' => 'big-five-25'],
                [
                    'name' => 'Inventario Big Five (versión reducida)',
                    'description' => 'Evaluación de los cinco grandes rasgos de personalidad: apertura, responsabilidad, extraversión, amabilidad y neuroticismo. 25 afirmaciones en escala Likert de 5 puntos.',
                    'category' => 'personalidad',
                    'time_limit_minutes' => 15,
                    'passing_score' => null,
                    'instructions' => 'Indica qué tan exacta es cada afirmación para describirte. No hay respuestas correctas o incorrectas — responde con honestidad.',
                    'sort_order' => 1,
                    'is_active' => true,
                    'is_required' => true,
                ]
            );

            // Limpia cualquier estructura previa para idempotencia real
            $test->sections()->delete();
            $test->questions()->delete();

            $globalSort = 0;

            foreach (self::ITEMS as $dimension => $items) {
                $section = PsychometricTestSection::create([
                    'psychometric_test_id' => $test->id,
                    'code' => $dimension,
                    'name' => ucfirst($dimension),
                    'description' => 'Ítems que evalúan la dimensión de '.$dimension.'.',
                    'time_limit_minutes' => null,
                    'sort_order' => $globalSort++,
                ]);

                foreach ($items as $localIndex => $item) {
                    $question = PsychometricQuestion::create([
                        'psychometric_test_id' => $test->id,
                        'psychometric_test_section_id' => $section->id,
                        'type' => QuestionType::Likert5->value,
                        'prompt' => $item['text'],
                        'dimension' => $dimension,
                        'weight' => 1,
                        'is_reverse_scored' => $item['reverse'],
                        'sort_order' => $localIndex,
                    ]);

                    foreach (self::LIKERT5 as $optIndex => $opt) {
                        PsychometricQuestionOption::create([
                            'psychometric_question_id' => $question->id,
                            'label' => $opt['label'],
                            'value' => (string) $opt['score'],
                            'score' => $opt['score'],
                            'is_correct' => false,
                            'sort_order' => $optIndex,
                        ]);
                    }
                }
            }
        });
    }
}
