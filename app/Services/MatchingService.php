<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Enums\VacancyTargetKind;
use App\Models\CandidateProfile;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Matching estructurado vacante ↔ candidato (PDF cosasfaltanteshumae,
 * "Ajuste en la lógica de matching"). Calcula un score 0-100 ponderado
 * de 6 ejes: kind, áreas, educación, experiencia, skills, salario.
 *
 * No es ML — son reglas deterministas, replicables y debuggeables.
 * El breakdown se devuelve para que la UI explique por qué cada
 * candidato sugerido está en la lista.
 */
class MatchingService
{
    private const WEIGHTS = [
        'kind' => 25,
        'areas' => 25,
        'education' => 15,
        'experience' => 15,
        'skills' => 15,
        'salary' => 5,
    ];

    /**
     * Estados visibles para sugerir (mismos que el directorio).
     *
     * @var list<string>
     */
    private const VISIBLE_STATES = [
        'activo',
        'en_proceso',
        'presentado_empresa',
        'entrevistado',
    ];

    /**
     * @return array{total: int, breakdown: array<string, int>}
     */
    public function score(Vacancy $vacancy, CandidateProfile $candidate): array
    {
        $breakdown = [
            'kind' => $this->scoreKind($vacancy, $candidate),
            'areas' => $this->scoreAreas($vacancy, $candidate),
            'education' => $this->scoreEducation($vacancy, $candidate),
            'experience' => $this->scoreExperience($vacancy, $candidate),
            'skills' => $this->scoreSkills($vacancy, $candidate),
            'salary' => $this->scoreSalary($vacancy, $candidate),
        ];

        $total = array_sum($breakdown);

        return [
            'total' => (int) min(100, max(0, $total)),
            'breakdown' => array_map(static fn (int $v): int => (int) $v, $breakdown),
        ];
    }

    /**
     * Devuelve los candidatos sugeridos para una vacante, ordenados por score
     * descendente. Filtra por membresía activa y estados visibles, y excluye
     * a quienes ya tengan asignación con la misma vacante.
     *
     * @return list<array{candidate: CandidateProfile, total: int, breakdown: array<string, int>}>
     */
    public function suggestForVacancy(Vacancy $vacancy, int $minScore = 0, int $limit = 20): array
    {
        /** @var Collection<int, CandidateProfile> $candidates */
        $candidates = CandidateProfile::query()
            ->with(['skills', 'languages', 'functionalAreas'])
            ->whereIn('state', self::VISIBLE_STATES)
            ->whereHas('user.memberships', function (Builder $m): void {
                $m->where('status', MembershipStatus::Active->value)
                    ->where('expires_at', '>', now());
            })
            ->whereDoesntHave('assignmentsForVacancy', function (Builder $a) use ($vacancy): void {
                $a->where('vacancy_id', $vacancy->id);
            })
            ->get();

        $scored = $candidates
            ->map(fn (CandidateProfile $c) => array_merge(
                ['candidate' => $c],
                $this->score($vacancy, $c),
            ))
            ->filter(fn (array $row) => $row['total'] >= $minScore)
            ->sortByDesc('total')
            ->take($limit)
            ->values()
            ->all();

        /** @var list<array{candidate: CandidateProfile, total: int, breakdown: array<string, int>}> $scored */
        return $scored;
    }

    private function scoreKind(Vacancy $vacancy, CandidateProfile $candidate): int
    {
        $target = $vacancy->target_candidate_kind;
        $kind = $candidate->candidate_kind;
        $weight = self::WEIGHTS['kind'];

        if ($target === VacancyTargetKind::Any) {
            return (int) ($weight * 0.6); // vacante abierta a ambos: match parcial
        }
        if ($kind === null) {
            return 0;
        }

        return $target->value === $kind->value ? $weight : 0;
    }

    private function scoreAreas(Vacancy $vacancy, CandidateProfile $candidate): int
    {
        $weight = self::WEIGHTS['areas'];
        $vacancyAreaId = $vacancy->functional_area_id;
        if ($vacancyAreaId === null) {
            return (int) ($weight * 0.4); // sin clasificación de vacante: match neutro
        }

        $areas = $candidate->functionalAreas;
        if ($areas->isEmpty()) {
            return $candidate->functional_area_id === $vacancyAreaId
                ? (int) ($weight * 0.6)
                : 0;
        }

        $hasArea = $areas->contains(fn ($a) => $a->id === $vacancyAreaId);
        if (! $hasArea) {
            return 0;
        }

        $isPrimary = $areas
            ->first(fn ($a) => $a->id === $vacancyAreaId)
            ?->getRelation('pivot')
            ?->getAttribute('is_primary');

        return $isPrimary ? $weight : (int) ($weight * 0.7);
    }

    private function scoreEducation(Vacancy $vacancy, CandidateProfile $candidate): int
    {
        $weight = self::WEIGHTS['education'];
        $required = $vacancy->degree_level_id;
        if ($required === null) {
            return $weight; // sin requisito → todos cumplen
        }

        $maxLevel = $candidate->educations()
            ->whereNotNull('degree_level_id')
            ->max('degree_level_id');

        if ($maxLevel === null) {
            return 0;
        }

        return ((int) $maxLevel) >= $required ? $weight : (int) ($weight * 0.5);
    }

    private function scoreExperience(Vacancy $vacancy, CandidateProfile $candidate): int
    {
        $weight = self::WEIGHTS['experience'];
        $min = $vacancy->min_years_of_experience;
        $max = $vacancy->max_years_of_experience;
        $years = $candidate->years_of_experience ?? 0;

        if ($min === null && $max === null) {
            return $weight; // sin requisito
        }

        if ($min !== null && $years < $min) {
            // Bajo el mínimo: damos parcial proporcional para no excluir.
            return $min > 0 ? (int) round($weight * min(1.0, $years / $min) * 0.5) : 0;
        }
        if ($max !== null && $years > $max) {
            // Sobre el máximo: aún cumple pero penalizado leve.
            return (int) round($weight * 0.7);
        }

        return $weight;
    }

    private function scoreSkills(Vacancy $vacancy, CandidateProfile $candidate): int
    {
        $weight = self::WEIGHTS['skills'];
        $vacancySkillIds = $vacancy->skills()->pluck('skills.id')->all();
        if ($vacancySkillIds === []) {
            return $weight; // vacante sin requisitos de skills → cumple
        }

        $candidateSkillIds = $candidate->skills->pluck('id')->all();
        $matched = count(array_intersect($vacancySkillIds, $candidateSkillIds));
        $ratio = $matched / count($vacancySkillIds);

        return (int) round($weight * $ratio);
    }

    private function scoreSalary(Vacancy $vacancy, CandidateProfile $candidate): int
    {
        $weight = self::WEIGHTS['salary'];
        $candidateMin = $candidate->expected_salary_min !== null
            ? (float) $candidate->expected_salary_min
            : null;
        $vacancyMax = $vacancy->salary_max !== null
            ? (float) $vacancy->salary_max
            : null;

        if ($candidateMin === null || $vacancyMax === null) {
            return $weight; // sin datos → no penalizamos
        }

        return $candidateMin <= $vacancyMax ? $weight : 0;
    }
}
