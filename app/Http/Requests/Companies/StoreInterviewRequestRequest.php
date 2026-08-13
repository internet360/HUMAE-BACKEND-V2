<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Enums\SalaryPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una solicitud de entrevistas: perfiles + vacante breve + dos horarios.
 *
 * La vacante que viaja aquí es intencionalmente corta. El requisito pide «lo
 * más breve posible, sólo con los datos necesarios para iniciar el proceso», y
 * lo necesario para que un reclutador arranque es qué puesto, qué se hace y
 * dónde. Todo lo demás se completa después, cuando ya hay conversación.
 *
 * No se aceptan aquí los términos comerciales de HUMAE —`fee_amount`,
 * `fee_percentage`, `sla_days`, `internal_notes`, `assigned_recruiter_id`— ni
 * `company_id`. `VacancyRequest` explica por qué (F-02, F-04, F-05); la lista
 * blanca de abajo es la misma decisión, escrita como ausencia.
 */
class StoreInterviewRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $periods = array_map(fn (SalaryPeriod $p) => $p->value, SalaryPeriod::cases());

        return [
            // Los perfiles elegidos, por referencia opaca — nunca por id.
            'candidate_references' => ['required', 'array', 'min:1', 'max:20'],
            'candidate_references.*' => ['required', 'uuid', 'distinct'],

            // La vacante breve.
            'vacancy' => ['required', 'array'],
            'vacancy.title' => ['required', 'string', 'max:200'],
            'vacancy.description' => ['required', 'string', 'max:10000'],
            'vacancy.position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'vacancy.functional_area_id' => ['sometimes', 'nullable', 'integer', 'exists:functional_areas,id'],
            'vacancy.career_level_id' => ['sometimes', 'nullable', 'integer', 'exists:career_levels,id'],
            'vacancy.vacancies_count' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'vacancy.country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'vacancy.state_id' => ['sometimes', 'nullable', 'integer', 'exists:states,id'],
            'vacancy.city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'vacancy.is_remote' => ['sometimes', 'boolean'],
            'vacancy.is_hybrid' => ['sometimes', 'boolean'],
            'vacancy.salary_currency_id' => ['sometimes', 'nullable', 'integer', 'exists:salary_currencies,id'],
            'vacancy.salary_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'vacancy.salary_max' => ['sometimes', 'nullable', 'numeric', 'gte:vacancy.salary_min'],
            'vacancy.salary_period' => ['sometimes', 'nullable', Rule::in($periods)],

            // Exactamente dos horarios. Ni uno ni tres: es regla de negocio, no
            // un rango cómodo. Con uno solo no hay nada que elegir para el
            // candidato; con más, la coordinación deja de ser una decisión y
            // pasa a ser una negociación.
            'interview_slots' => ['required', 'array', 'size:2'],
            'interview_slots.*' => ['required', 'date', 'after:now', 'distinct'],

            'timezone' => ['sometimes', 'string', 'timezone', 'max:64'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'interview_slots.size' => 'Debes proponer exactamente dos horarios para la entrevista.',
            'interview_slots.*.after' => 'Los horarios propuestos deben ser posteriores a este momento.',
            'interview_slots.*.distinct' => 'Los dos horarios propuestos deben ser distintos.',
            'candidate_references.required' => 'Selecciona al menos un perfil antes de enviar la solicitud.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'candidate_references' => 'perfiles seleccionados',
            'interview_slots' => 'horarios propuestos',
            'vacancy.title' => 'título de la vacante',
            'vacancy.description' => 'descripción de la vacante',
            'note' => 'mensaje',
        ];
    }
}
