<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Enums\Priority;
use App\Enums\SalaryPeriod;
use App\Enums\VacancyTargetKind;
use App\Http\Requests\Concerns\RestrictsFieldsByRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The one place that decides which vacancy fields each role may write.
 *
 * Serves three endpoints — `POST/PATCH /vacancies` (HUMAE) and
 * `POST/PATCH /me/company/vacancies` (the client company) — which is exactly
 * why the rule belongs here. When the controllers each passed
 * `$request->validated()` straight through, the company wrote HUMAE's
 * commercial terms and internal notes on both surfaces (F-04, F-05), and
 * re-parented its vacancy into another client's account (F-02).
 */
class VacancyRequest extends FormRequest
{
    use RestrictsFieldsByRole;

    /**
     * HUMAE's side of the mandate: what it charges, what it commits to, who
     * runs it, and what it writes down about the client.
     *
     * §6 — "Agregar notas internas: Empresa cliente ❌".
     *
     * @return list<string>
     */
    protected function staffOnlyFields(): array
    {
        return [
            'internal_notes',
            'fee_amount',
            'fee_percentage',
            'sla_days',
            'assigned_recruiter_id',
        ];
    }

    /**
     * §6 — "Crear vacante: Empresa cliente ✅ (propia)". The word that matters
     * is "propia", and `exists:companies,id` never checked it.
     *
     * @return list<string>
     */
    protected function companyScopedFields(): array
    {
        return ['company_id'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $required = $isCreate ? 'required' : 'sometimes';

        $priorities = array_map(fn (Priority $p) => $p->value, Priority::cases());
        $periods = array_map(fn (SalaryPeriod $p) => $p->value, SalaryPeriod::cases());
        $targetKinds = array_map(fn (VacancyTargetKind $k) => $k->value, VacancyTargetKind::cases());

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'title' => [$required, 'string', 'max:200'],
            'description' => [$required, 'string', 'max:10000'],
            'responsibilities' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'requirements' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'benefits' => ['sometimes', 'nullable', 'string', 'max:10000'],

            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'functional_area_id' => ['sometimes', 'nullable', 'integer', 'exists:functional_areas,id'],
            'vacancy_category_id' => ['sometimes', 'nullable', 'integer', 'exists:vacancy_categories,id'],
            'target_candidate_kind' => ['sometimes', Rule::in($targetKinds)],
            'vacancy_type_id' => ['sometimes', 'nullable', 'integer', 'exists:vacancy_types,id'],
            'vacancy_shift_id' => ['sometimes', 'nullable', 'integer', 'exists:vacancy_shifts,id'],
            'career_level_id' => ['sometimes', 'nullable', 'integer', 'exists:career_levels,id'],
            'degree_level_id' => ['sometimes', 'nullable', 'integer', 'exists:degree_levels,id'],

            'min_years_of_experience' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:50'],
            'max_years_of_experience' => ['sometimes', 'nullable', 'integer', 'gte:min_years_of_experience'],
            'min_age' => ['sometimes', 'nullable', 'integer', 'min:16', 'max:99'],
            'max_age' => ['sometimes', 'nullable', 'integer', 'gte:min_age', 'max:99'],
            'gender_preference' => ['sometimes', 'nullable', 'in:any,male,female'],
            'vacancies_count' => ['sometimes', 'integer', 'min:1', 'max:500'],

            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'integer', 'exists:states,id'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'is_remote' => ['sometimes', 'boolean'],
            'is_hybrid' => ['sometimes', 'boolean'],
            'work_location' => ['sometimes', 'nullable', 'string', 'max:300'],

            'salary_currency_id' => ['sometimes', 'nullable', 'integer', 'exists:salary_currencies,id'],
            'salary_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'salary_max' => ['sometimes', 'nullable', 'numeric', 'gte:salary_min'],
            'salary_period' => ['sometimes', 'nullable', Rule::in($periods)],
            'salary_is_public' => ['sometimes', 'boolean'],

            'priority' => ['sometimes', Rule::in($priorities)],
            'closes_at' => ['sometimes', 'nullable', 'date', 'after:today'],
            'fee_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'fee_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'sla_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'assigned_recruiter_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],

            // No hay campo para asignar candidatos al crear una vacante.
            // Asignar es la curación de HUMAE (ARCHITECTURE.md §6, «Asignar
            // candidatos a vacante — Empresa cliente: ❌») y se hace desde
            // POST /vacancies/{vacancy}/assignments, cerrado a recruiter/admin.
        ];
    }
}
