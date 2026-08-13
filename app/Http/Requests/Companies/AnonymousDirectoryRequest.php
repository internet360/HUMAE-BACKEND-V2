<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Enums\CandidateKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros permitidos en la vista previa anónima del directorio.
 *
 * La lista es corta a propósito y no incluye `state`, `has_active_membership`
 * ni `is_favorite`: son los tres que `DirectorySearchService::searchAnonymous()`
 * fuerza del lado del servidor. Validarlos aquí como "no permitidos" es la
 * segunda mitad del candado — si alguien mañana los conecta en el servicio,
 * este request los rechaza antes de que lleguen.
 *
 * La autorización vive en la Policy (`viewAnonymousDirectory`), no aquí.
 */
class AnonymousDirectoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kinds = array_map(fn (CandidateKind $k) => $k->value, CandidateKind::cases());

        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],

            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'integer', 'exists:states,id'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'career_level_id' => ['sometimes', 'nullable', 'integer', 'exists:career_levels,id'],
            'functional_area_id' => ['sometimes', 'nullable', 'integer', 'exists:functional_areas,id'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'candidate_kind' => ['sometimes', 'nullable', Rule::in($kinds)],
            'availability' => ['sometimes', 'nullable', 'string', 'max:50'],

            'years_exp_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'years_exp_max' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60', 'gte:years_exp_min'],
            'salary_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            'open_to_remote' => ['sometimes', 'boolean'],
            'open_to_relocation' => ['sometimes', 'boolean'],

            'modalities' => ['sometimes', 'array', 'max:3'],
            'modalities.*' => ['string', Rule::in(['onsite', 'remote', 'hybrid'])],

            'work_schedules' => ['sometimes', 'array', 'max:10'],
            'work_schedules.*' => ['integer', 'exists:vacancy_types,id'],

            'skills' => ['sometimes', 'array', 'max:10'],
            'skills.*' => ['integer', 'exists:skills,id'],

            'languages' => ['sometimes', 'array', 'max:10'],
            'languages.*' => ['integer', 'exists:languages,id'],

            'functional_area_ids' => ['sometimes', 'array', 'max:10'],
            'functional_area_ids.*' => ['integer', 'exists:functional_areas,id'],

            'primary_functional_area_id' => ['sometimes', 'nullable', 'integer', 'exists:functional_areas,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'q' => 'búsqueda',
            'years_exp_min' => 'años de experiencia mínimos',
            'years_exp_max' => 'años de experiencia máximos',
            'salary_max' => 'salario máximo',
            'modalities' => 'modalidades',
            'work_schedules' => 'jornadas',
        ];
    }
}
