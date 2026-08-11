<?php

declare(strict_types=1);

namespace App\Http\Requests\Candidate;

use App\Models\PsychometricAttempt;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Respuestas de un intento psicométrico.
 *
 * Acá vive el límite de confianza del módulo. El candidato declara QUÉ eligió;
 * nunca CUÁNTO vale. El puntaje lo deriva el servidor desde la opción elegida
 * (`PsychometricTestService::saveAnswers()`).
 *
 * Antes esta validación era un `$request->validate()` inline en el controller y
 * aceptaba `answers.*.score` del cliente, que el scoring además prefería sobre
 * el puntaje real de la opción. Un candidato podía mandar `score: 999999` y
 * fabricarse su propio resultado psicométrico.
 */
class SavePsychometricAnswersRequest extends FormRequest
{
    /**
     * La pertenencia del intento se resuelve ACÁ y no en el controller.
     *
     * No es preferencia de estilo: `authorize()` corre antes que las reglas, y
     * las reglas de esta clase consultan la prueba del intento para validar la
     * estructura. Con el chequeo en el controller —después de la validación— un
     * candidato podía apuntar al intento de otro y deducir a qué prueba
     * pertenece según recibiera 422 «la pregunta no pertenece» o 200.
     *
     * Aborta 404 en lugar de devolver `false` (403) para no confirmar que el
     * intento existe, igual que `ResolvesCandidateProfile::ensureOwned()`.
     */
    public function authorize(): bool
    {
        $attempt = $this->route('attempt');

        if (! $attempt instanceof PsychometricAttempt) {
            return true; // Sin binding no hay nada que autorizar todavía.
        }

        /** @var User|null $user */
        $user = $this->user();

        $profile = $user !== null
            ? app(ProfileService::class)->find($user)
            : null;

        if ($profile === null || $profile->id !== $attempt->candidate_profile_id) {
            abort(HttpStatus::HTTP_NOT_FOUND);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_id' => ['nullable', 'integer'],
            'answers.*.value' => ['nullable', 'string', 'max:400'],
            'answers.*.time_spent_seconds' => ['nullable', 'integer', 'min:0'],

            // `prohibited` y no simplemente ausente: si un cliente viejo todavía
            // manda el puntaje, conviene un 422 ruidoso antes que ignorarlo en
            // silencio y dejar la duda de si se aplicó.
            'answers.*.score' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answers.*.score.prohibited' => 'El puntaje lo calcula el servidor: no se acepta desde el cliente.',
        ];
    }

    /**
     * Validación estructural contra la prueba del intento.
     *
     * Dos agujeros que no cierra ninguna regla de campo:
     *
     * 1. `question_id` de otra prueba. El servicio lo descartaba en silencio con
     *    un `continue`, así que el candidato recibía 200 OK y sus respuestas
     *    desaparecían. Ahora es 422.
     * 2. `option_id` de OTRA pregunta. Nadie verificaba el par. Con el puntaje
     *    derivado de la opción, esto es el camino de explotación directo: mandar
     *    el id de la opción de 5 puntos de otra pregunta para una donde
     *    corresponde 1.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $attempt = $this->route('attempt');
                $answers = $this->input('answers');

                if (! $attempt instanceof PsychometricAttempt || ! is_array($answers)) {
                    return;
                }

                $optionsByQuestion = $attempt->test?->optionIdsByQuestion() ?? [];

                foreach ($answers as $index => $answer) {
                    if (! is_array($answer)) {
                        continue;
                    }

                    $questionId = filter_var($answer['question_id'] ?? null, FILTER_VALIDATE_INT);

                    if ($questionId === false || ! array_key_exists($questionId, $optionsByQuestion)) {
                        $validator->errors()->add(
                            "answers.{$index}.question_id",
                            'La pregunta no pertenece a esta prueba.',
                        );

                        continue;
                    }

                    $optionId = $answer['option_id'] ?? null;

                    if ($optionId === null || $optionId === '') {
                        continue;
                    }

                    $optionId = filter_var($optionId, FILTER_VALIDATE_INT);

                    if ($optionId === false || ! in_array($optionId, $optionsByQuestion[$questionId], true)) {
                        $validator->errors()->add(
                            "answers.{$index}.option_id",
                            'La opción no pertenece a la pregunta.',
                        );
                    }
                }
            },
        ];
    }
}
