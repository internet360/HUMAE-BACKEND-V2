<?php

declare(strict_types=1);

namespace App\Http\Requests\Interviews;

use App\Enums\InterviewMode;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScheduleInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Las entrevistas solo soportan modalidad online en esta fase. Las opciones
     * "presencial" y "telefonica" del enum siguen existiendo por compatibilidad
     * con datos históricos, pero no se aceptan en este endpoint.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();
        $isCompany = $user !== null && $user->hasRole(UserRole::CompanyUser->value)
            && ! $user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value]);

        return [
            'vacancy_assignment_id' => ['required', 'integer', 'exists:vacancy_assignments,id'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'round' => ['sometimes', 'integer', 'min:1', 'max:20'],
            // Solo modalidad online por ahora; rechazamos los demás valores del enum.
            'mode' => ['sometimes', Rule::in([InterviewMode::Online->value])],
            'scheduled_at' => ['required', 'date', 'after:now'],
            // La empresa propone dos horarios; el candidato escoge uno al confirmar.
            // El reclutador no necesita el segundo slot (lo agenda directamente).
            'alternate_scheduled_at' => [
                $isCompany ? 'required' : 'sometimes',
                'nullable',
                'date',
                'after:now',
                'different:scheduled_at',
            ],
            'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:480'],
            'timezone' => ['sometimes', 'string', 'max:60'],
            // El meeting_url lo agrega el reclutador después de que el candidato
            // confirma la fecha; la empresa no debe poder establecerlo.
            'meeting_url' => $isCompany
                ? ['prohibited']
                : ['sometimes', 'nullable', 'url', 'max:600'],
            'meeting_provider' => $isCompany
                ? ['prohibited']
                : ['sometimes', 'nullable', 'string', 'max:40'],
            'meeting_id' => $isCompany
                ? ['prohibited']
                : ['sometimes', 'nullable', 'string', 'max:120'],
            // Solo aplica para presencial — deshabilitado en esta fase.
            'location' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'alternate_scheduled_at.required' => 'Como empresa, debes proponer dos horarios para que el candidato escoja uno.',
            'alternate_scheduled_at.different' => 'Los dos horarios propuestos deben ser distintos.',
            'meeting_url.prohibited' => 'El enlace de la reunión lo agrega el reclutador HUMAE tras la confirmación del candidato.',
            'meeting_provider.prohibited' => 'El enlace de la reunión lo agrega el reclutador HUMAE.',
            'meeting_id.prohibited' => 'El enlace de la reunión lo agrega el reclutador HUMAE.',
            'location.prohibited' => 'Por ahora solo se admiten entrevistas online.',
            'mode.in' => 'Por ahora solo se admiten entrevistas online.',
        ];
    }
}
