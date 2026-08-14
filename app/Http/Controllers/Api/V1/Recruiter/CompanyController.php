<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruiter;

use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\CompanyRequest;
use App\Http\Resources\V1\Companies\CompanyResource;
use App\Models\Company;
use Cocur\Slugify\Slugify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class CompanyController extends Controller
{
    /**
     * Listado de empresas cliente con búsqueda y filtros.
     *
     * `latestContract` va precargado siempre —no bajo un flag— porque el estado
     * del contrato dejó de ser un detalle del expediente y pasó a ser una
     * columna del listado: es lo primero que el equipo necesita ver de un
     * cliente, y resolverlo con un request por tarjeta serían N+1 peticiones
     * desde el navegador para pintar una lista.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        $contractFilter = $request->string('contract_status')->toString();

        $companies = Company::query()
            ->with('latestContract')
            ->withCount($this->pendingAddendaCount())
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where(function ($inner) use ($request): void {
                    $term = '%'.$request->string('q').'%';
                    $inner->where('legal_name', 'like', $term)
                        ->orWhere('trade_name', 'like', $term)
                        ->orWhere('slug', 'like', $term)
                        ->orWhere('rfc', 'like', $term)
                        ->orWhere('contact_email', 'like', $term)
                        ->orWhere('contact_name', 'like', $term);
                }),
            )
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')->toString()),
            )
            ->when(
                $request->filled('is_verified'),
                fn ($q) => $q->where('is_verified', $request->boolean('is_verified')),
            )
            ->when(
                $request->filled('account_manager_id'),
                fn ($q) => $q->where('account_manager_id', $request->integer('account_manager_id')),
            )
            /*
             * «Firmado» y «pendiente» se preguntan sobre el contrato MAESTRO,
             * con el mismo `whereNull('vacancy_id')` que usa
             * `Company::latestContract`. Sin esa condición, una empresa sin
             * maestro pero con una adenda de honorarios saldría en «firmadas», y
             * es precisamente la que hay que perseguir: está operando con la
             * cláusula Primera sin firmar.
             */
            ->when($contractFilter === 'signed', fn ($q) => $q->whereHas(
                'contracts',
                fn ($c) => $c->whereNull('vacancy_id'),
            ))
            ->when($contractFilter === 'pending', fn ($q) => $q->whereDoesntHave(
                'contracts',
                fn ($c) => $c->whereNull('vacancy_id'),
            ))
            ->orderBy('legal_name')
            // Un tope explícito y no `per_page` a secas: el parámetro lo escribe
            // el cliente, y sin techo un `per_page=100000` convierte un listado
            // en una descarga de toda la cartera. 100 alcanza para llenar el
            // selector de empresas del panel de usuarios de una sola vez.
            ->paginate(min(max($request->integer('per_page', 20), 1), 100))
            ->withQueryString();

        return $this->success(
            message: 'Empresas.',
            data: CompanyResource::collection($companies),
            meta: [
                'pagination' => [
                    'current_page' => $companies->currentPage(),
                    'per_page' => $companies->perPage(),
                    'total' => $companies->total(),
                    'last_page' => $companies->lastPage(),
                ],
            ],
        );
    }

    public function store(CompanyRequest $request): JsonResponse
    {
        $this->authorize('create', Company::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if (empty($data['slug'])) {
            $slugify = new Slugify;
            $data['slug'] = $this->uniqueSlug(
                $slugify->slugify((string) ($data['trade_name'] ?? $data['legal_name'] ?? 'empresa')),
            );
        }

        $company = Company::create($data);

        return $this->success(
            message: 'Empresa creada.',
            data: CompanyResource::make($company),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $company->load(['members.user', 'latestContract']);
        $company->loadCount($this->pendingAddendaCount());

        return $this->success(
            message: 'Empresa.',
            data: CompanyResource::make($company),
        );
    }

    public function update(CompanyRequest $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        $company->update($request->validated());

        return $this->success(
            message: 'Empresa actualizada.',
            data: CompanyResource::make($company->fresh()),
        );
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        $company->delete();

        return $this->success(message: 'Empresa archivada.', status: HttpStatus::HTTP_NO_CONTENT);
    }

    /**
     * Vacantes de la empresa con honorario propio y sin adenda firmada.
     *
     * Es el número que hace visible un riesgo que hoy sólo se descubre entrando
     * vacante por vacante: HUMAE está por facturar un porcentaje que la empresa
     * nunca firmó. Mismo criterio que `Vacancy::hasPendingFeeAddendum()`, pero
     * como subconsulta para no traer las filas.
     *
     * @return array<string, callable>
     */
    private function pendingAddendaCount(): array
    {
        return [
            'vacancies as pending_addenda_count' => fn ($q) => $q
                ->whereDoesntHave('signedAddendum')
                ->where(fn ($inner) => $inner
                    ->where('fee_percentage', '>', 0)
                    ->orWhere('fee_amount', '>', 0)),
        ];
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $i = 1;
        while (Company::acrossCompanies()->where('slug', $slug)->exists()) {
            $i++;
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
