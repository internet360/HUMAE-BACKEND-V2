<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttemptStatus;
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
        $this->applyModalityFilter($query, $request);
        $this->applyWorkSchedulesFilter($query, $request);
        $this->applySkillsFilter($query, $request);
        $this->applyLanguagesFilter($query, $request);
        $this->applyFunctionalAreasFilter($query, $request);
        $this->applyFavoriteFilter($query, $request);

        $query->orderByDesc('updated_at');

        $perPage = min(50, max(1, (int) $request->input('per_page', 20)));

        return $query->paginate($perPage);
    }

    /**
     * Estados de candidato que la empresa cliente puede alcanzar.
     *
     * Fuente única: la usan la vista anónima y el alta de solicitudes, que
     * tiene que revalidar lo mismo al resolver referencias. Dos listas serían
     * dos verdades, y la que se olvide de actualizarse deja pasar a alguien.
     *
     * @return list<string>
     */
    public static function companyVisibleStates(): array
    {
        return self::VISIBLE_STATES;
    }

    /**
     * Vista previa anónima del directorio, para la empresa cliente.
     *
     * Comparte los filtros estructurados con `search()` y se aparta en cuatro
     * puntos, todos deliberados:
     *
     * - **Membresía activa forzada.** `search()` la deja apagar con
     *   `has_active_membership=0` porque HUMAE necesita ver su base completa.
     *   La empresa no: sólo ve talento vigente.
     * - **Estado forzado.** `search()` acepta `state` para que el reclutador
     *   consulte etapas internas. Aquí se fija la lista visible; si no, la
     *   empresa podría listar descartados y leer a quién rechazó HUMAE.
     * - **Búsqueda libre sólo contra `headline`.** En `search()` corre también
     *   contra nombre y apellido, y eso convertiría el buscador en un oráculo:
     *   escribir un nombre y leer el conteo de resultados confirma si esa
     *   persona está en la base. Es exactamente lo que la vista anónima evita,
     *   y sería una fuga silenciosa porque la respuesta no muestra nombres.
     * - **Sin favoritos.** `directory_favorites` está indexada por
     *   `recruiter_id`; no hay favoritos de empresa que aplicar.
     *
     * No se hace eager load de `user`: el recurso anónimo no debe poder llegar
     * ahí ni por accidente.
     *
     * @return LengthAwarePaginator<int, CandidateProfile>
     */
    public function searchAnonymous(Request $request): LengthAwarePaginator
    {
        $query = CandidateProfile::query()
            ->with(['skills', 'languages'])
            ->withCount([
                'attempts as completed_psychometrics_count' => function (Builder $q): void {
                    $q->where('status', AttemptStatus::Completed->value);
                },
            ]);

        $this->applyActiveMembership($query);
        $query->whereIn('state', self::VISIBLE_STATES);

        $this->applyHeadlineSearch($query, $request);
        $this->applyScalarFilters($query, $request);
        $this->applyExperienceFilters($query, $request);
        $this->applySalaryFilter($query, $request);
        $this->applyFlagFilters($query, $request);
        $this->applyModalityFilter($query, $request);
        $this->applyWorkSchedulesFilter($query, $request);
        $this->applySkillsFilter($query, $request);
        $this->applyLanguagesFilter($query, $request);
        $this->applyFunctionalAreasFilter($query, $request);

        $query->orderByDesc('updated_at');

        $perPage = min(50, max(1, (int) $request->input('per_page', 20)));

        return $query->paginate($perPage);
    }

    /**
     * Búsqueda libre restringida al titular profesional. Ver `searchAnonymous()`
     * para por qué no incluye nombre ni resumen.
     *
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyHeadlineSearch(Builder $query, Request $request): void
    {
        if (! $request->filled('q')) {
            return;
        }

        $query->where('headline', 'like', '%'.trim((string) $request->input('q')).'%');
    }

    /**
     * Un perfil del padrón anónimo, buscado por su referencia opaca.
     *
     * Aplica las MISMAS dos condiciones que `searchAnonymous()` —membresía
     * vigente y estado visible— y por eso vive acá: si la regla se copiara al
     * controller que sirve la foto, el día que cambie una de las dos quedaría
     * un camino sirviendo a alguien que ya salió del padrón.
     */
    public function visibleProfileByReference(string $reference): ?CandidateProfile
    {
        $query = CandidateProfile::query()
            ->with('user')
            ->where('public_reference', $reference)
            ->whereIn('state', self::VISIBLE_STATES);

        $this->applyActiveMembership($query);

        return $query->first();
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyActiveMembership(Builder $query): void
    {
        $query->whereHas('user.memberships', function (Builder $m): void {
            $m->where('status', MembershipStatus::Active->value)
                ->where('expires_at', '>', now());
        });
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

        $this->applyActiveMembership($query);
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
     * Filtra por modalidad de trabajo a la que el candidato está abierto:
     * presencial, remoto, híbrido. Acepta uno o más en el array `modalities[]`
     * y aplica OR-semantics (el candidato matchea si está abierto a CUALQUIERA
     * de las modalidades pedidas). También sigue aceptando el flag legacy
     * `open_to_remote` por compatibilidad.
     *
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyModalityFilter(Builder $query, Request $request): void
    {
        $raw = $request->input('modalities', []);
        if (! \is_array($raw)) {
            return;
        }

        $allowed = ['onsite', 'remote', 'hybrid'];
        $modalities = array_values(array_intersect($raw, $allowed));

        if ($modalities === []) {
            return;
        }

        $columnByModality = [
            'onsite' => 'open_to_onsite',
            'remote' => 'open_to_remote',
            'hybrid' => 'open_to_hybrid',
        ];

        $query->where(function (Builder $q) use ($modalities, $columnByModality): void {
            foreach ($modalities as $m) {
                $q->orWhere($columnByModality[$m], true);
            }
        });
    }

    /**
     * Filtra por jornada laboral. Acepta uno o más IDs del catálogo
     * `vacancy_types` en `work_schedules[]` y aplica OR-semantics: el
     * candidato matchea si está abierto a CUALQUIERA de las jornadas pedidas.
     *
     * @param  Builder<CandidateProfile>  $query
     */
    private function applyWorkSchedulesFilter(Builder $query, Request $request): void
    {
        $ids = $this->arrayIds($request, 'work_schedules');
        if ($ids === []) {
            return;
        }

        $query->whereHas('workSchedules', function (Builder $q) use ($ids): void {
            $q->whereIn('vacancy_types.id', $ids);
        });
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
