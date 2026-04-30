<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateState;
use App\Enums\MembershipStatus;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DirectorySearchService
{
    /**
     * Estados que por defecto son visibles para reclutadores.
     * Se pueden sobreescribir con el filtro `state`.
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
     * @return LengthAwarePaginator<int, CandidateProfile>
     */
    public function search(Request $request): LengthAwarePaginator
    {
        $query = CandidateProfile::query()
            ->with([
                'user',
                'skills',
                'languages',
            ]);

        $this->applyMembershipFilter($query, $request);
        $this->applyStateFilter($query, $request);
        $this->applyTextSearch($query, $request);
        $this->applyScalarFilters($query, $request);
        $this->applyExperienceFilters($query, $request);
        $this->applySalaryFilter($query, $request);
        $this->applyFlagFilters($query, $request);
        $this->applySkillsFilter($query, $request);
        $this->applyLanguagesFilter($query, $request);
        $this->applyFunctionalAreasFilter($query, $request);
        $this->applyFavoriteFilter($query, $request);

        $query->orderByDesc('updated_at');

        $perPage = min(50, max(1, (int) $request->input('per_page', 20)));

        return $query->paginate($perPage);
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyMembershipFilter(Builder $query, Request $request): void
    {
        // Default: sólo candidatos con membresía activa. Se puede desactivar con `has_active_membership=0`.
        $wantsActive = (bool) $request->input('has_active_membership', true);

        if (! $wantsActive) {
            return;
        }

        $query->whereHas('user.memberships', function (Builder $m): void {
            $m->where('status', MembershipStatus::Active->value)
                ->where('expires_at', '>', now());
        });
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyStateFilter(Builder $query, Request $request): void
    {
        if ($request->filled('state')) {
            $query->where('state', (string) $request->input('state'));

            return;
        }

        $query->whereIn('state', self::VISIBLE_STATES);
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyTextSearch(Builder $query, Request $request): void
    {
        if (! $request->filled('q')) {
            return;
        }

        $term = '%'.trim((string) $request->input('q')).'%';

        $query->where(function (Builder $q) use ($term): void {
            $q->where('first_name', 'like', $term)
                ->orWhere('last_name', 'like', $term)
                ->orWhere('headline', 'like', $term)
                ->orWhere('summary', 'like', $term);
        });
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyScalarFilters(Builder $query, Request $request): void
    {
        foreach ([
            'country_id',
            'state_id',
            'city_id',
            'career_level_id',
            'functional_area_id',
            'position_id',
            'availability',
            'candidate_kind',
        ] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyExperienceFilters(Builder $query, Request $request): void
    {
        if ($request->filled('years_exp_min')) {
            $query->where('years_of_experience', '>=', (int) $request->input('years_exp_min'));
        }

        if ($request->filled('years_exp_max')) {
            $query->where('years_of_experience', '<=', (int) $request->input('years_exp_max'));
        }
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     */
    private function applySalaryFilter(Builder $query, Request $request): void
    {
        if (! $request->filled('salary_max')) {
            return;
        }

        $max = (float) $request->input('salary_max');

        // El candidato debe estar dispuesto a aceptar hasta ese máximo:
        // expected_salary_min <= salary_max (lo que la empresa está dispuesta a pagar)
        $query->where(function (Builder $q) use ($max): void {
            $q->whereNull('expected_salary_min')
                ->orWhere('expected_salary_min', '<=', $max);
        });
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyFlagFilters(Builder $query, Request $request): void
    {
        if ($request->has('open_to_remote')) {
            $query->where('open_to_remote', $request->boolean('open_to_remote'));
        }

        if ($request->has('open_to_relocation')) {
            $query->where('open_to_relocation', $request->boolean('open_to_relocation'));
        }
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     */
    private function applySkillsFilter(Builder $query, Request $request): void
    {
        $skillIds = $this->arrayIds($request, 'skills');
        if ($skillIds === []) {
            return;
        }

        // AND semántico: el candidato debe tener TODAS las skills pedidas.
        foreach ($skillIds as $skillId) {
            $query->whereHas('skills', function (Builder $q) use ($skillId): void {
                $q->where('skills.id', $skillId);
            });
        }
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyLanguagesFilter(Builder $query, Request $request): void
    {
        $languageIds = $this->arrayIds($request, 'languages');
        if ($languageIds === []) {
            return;
        }

        foreach ($languageIds as $languageId) {
            $query->whereHas('languages', function (Builder $q) use ($languageId): void {
                $q->where('languages.id', $languageId);
            });
        }
    }

    /**
     * Filtra por áreas de interés del candidato (PDF cosasfaltanteshumae,
     * "Ajuste en el directorio interno": filtro por áreas de interés laboral
     * y compatibilidad entre área de interés y vacante). Acepta:
     *   - functional_area_ids[]=1&functional_area_ids[]=2 → OR semantics
     *   - primary_functional_area_id=3 → solo si es el área principal
     * El filtro legacy `functional_area_id` sigue funcionando vía
     * applyScalarFilters() y matchea contra el campo single en el perfil.
     *
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyFunctionalAreasFilter(Builder $query, Request $request): void
    {
        $areaIds = $this->arrayIds($request, 'functional_area_ids');
        if ($areaIds !== []) {
            $query->whereHas('functionalAreas', function (Builder $q) use ($areaIds): void {
                $q->whereIn('functional_areas.id', $areaIds);
            });
        }

        if ($request->filled('primary_functional_area_id')) {
            $primaryId = (int) $request->input('primary_functional_area_id');
            $query->whereHas('functionalAreas', function (Builder $q) use ($primaryId): void {
                $q->where('functional_areas.id', $primaryId)
                    ->where('candidate_functional_areas.is_primary', true);
            });
        }
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyFavoriteFilter(Builder $query, Request $request): void
    {
        if (! $request->boolean('is_favorite')) {
            return;
        }

        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return;
        }

        $query->whereIn(
            'id',
            fn ($q) => $q->select('candidate_profile_id')
                ->from('directory_favorites')
                ->where('recruiter_id', $user->id),
        );
    }

    /**
     * @return list<int>
     */
    private function arrayIds(Request $request, string $key): array
    {
        $raw = $request->input($key, []);
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($v) => (int) $v,
            $raw,
        ), static fn (int $v) => $v > 0));
    }

    /**
     * Estados válidos para exponer al filtro público. Incluye los internos
     * para que admin/recruiter puedan consultarlos explícitamente.
     *
     * @return list<string>
     */
    public static function visibleStates(): array
    {
        return array_map(fn (CandidateState $s) => $s->value, CandidateState::cases());
    }
}
