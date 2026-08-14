<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\Vacancy;
use App\Support\Contracts\ContractLedgerEntry;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Historial completo de instrumentos de una empresa: lo firmado, lo anulado y
 * lo que falta firmar.
 *
 * Es un servicio de LECTURA. No emite nada; junta en una sola lista dos cosas
 * que viven en lugares distintos:
 *
 *   - Las filas de `company_contracts`, incluidas las anuladas (`withTrashed`).
 *     Una anulación no es un borrado: el PDF, la huella y la constancia siguen
 *     siendo la evidencia de lo que se aceptó, y esconderlas del historial es
 *     esconder justo lo que alguien vendría a auditar.
 *   - Las ausencias: la empresa sin contrato maestro y la vacante con honorario
 *     propio sin adenda. Ninguna de las dos tiene fila, y las dos son
 *     exactamente lo que el equipo necesita ver.
 */
class ContractLedgerService
{
    public function __construct(
        private readonly CompanyContractService $contracts,
    ) {}

    /**
     * @return Collection<int, ContractLedgerEntry>
     */
    public function forCompany(Company $company): Collection
    {
        return $this->masterEntries($company)
            ->concat($this->addendumEntries($company))
            /*
             * `sort()` con un comparador de dos argumentos, no `sortBy()` con una
             * lista de extractores.
             *
             * Es la diferencia entre ordenar y no ordenar: cuando `sortBy()`
             * recibe un arreglo, trata cada callable como un COMPARADOR
             * `fn ($a, $b)`, no como «dame la clave de este elemento». Un
             * extractor de un solo argumento se traga el primero, ignora el
             * segundo y devuelve la clave como si fuera el resultado de la
             * comparación — el orden que sale de ahí no significa nada.
             */
            ->sort(fn (ContractLedgerEntry $a, ContractLedgerEntry $b): int => $this->rank($a) <=> $this->rank($b))
            ->values();
    }

    /**
     * Criterio de orden del historial, del más importante al menos.
     *
     * Primero el estado y no el tipo de instrumento: lo pendiente es lo único
     * sobre lo que alguien puede actuar, y enterrarlo debajo de tres contratos
     * ya firmados es la forma más segura de que nadie lo vea. Después el maestro
     * antes que las adendas, que es la jerarquía real entre los dos. Y al final
     * el más reciente arriba.
     *
     * @return array{int, int, int}
     */
    private function rank(ContractLedgerEntry $entry): array
    {
        return [
            $this->statusRank($entry->status),
            $entry->kind === ContractLedgerEntry::KIND_MASTER ? 0 : 1,
            -($entry->contract?->signed_at?->getTimestamp() ?? 0),
        ];
    }

    /**
     * Conteo por estado, para el encabezado del historial.
     *
     * @param  Collection<int, ContractLedgerEntry>  $entries
     * @return array{signed: int, pending: int, voided: int, total: int}
     */
    public function summarize(Collection $entries): array
    {
        return [
            'signed' => $entries->where('status', ContractLedgerEntry::STATUS_SIGNED)->count(),
            'pending' => $entries->where('status', ContractLedgerEntry::STATUS_PENDING)->count(),
            'voided' => $entries->where('status', ContractLedgerEntry::STATUS_VOIDED)->count(),
            'total' => $entries->count(),
        ];
    }

    /**
     * Los términos que se firmarían en una entrada pendiente, o el motivo por el
     * que hoy no se puede emitir.
     *
     * `currentTerms()` y `addendumTerms()` lanzan cuando faltan condiciones
     * comerciales o la vacante no tiene honorario propio. Acá eso no es un error
     * del request: es información: «esta empresa no puede firmar todavía, y este
     * es el motivo». Se devuelve, no se propaga.
     *
     * @return array{terms: array<string, mixed>|null, blocker: string|null}
     */
    public function pendingTerms(ContractLedgerEntry $entry): array
    {
        if ($entry->status !== ContractLedgerEntry::STATUS_PENDING) {
            return ['terms' => null, 'blocker' => null];
        }

        try {
            $terms = $entry->vacancy !== null
                ? $this->contracts->addendumTerms($entry->vacancy)
                : $this->contracts->currentTerms();

            return ['terms' => $terms, 'blocker' => null];
        } catch (Throwable $e) {
            return ['terms' => null, 'blocker' => $e->getMessage()];
        }
    }

    /**
     * @return Collection<int, ContractLedgerEntry>
     */
    private function masterEntries(Company $company): Collection
    {
        /** @var Collection<int, CompanyContract> $masters */
        $masters = CompanyContract::acrossCompanies()
            ->withTrashed()
            ->where('company_id', $company->id)
            ->whereNull('vacancy_id')
            ->with('signedBy')
            ->orderByDesc('signed_at')
            ->get();

        // El vigente es el más reciente NO anulado. Se calcula acá y no con
        // `first()` sobre la lista completa porque una anulación reciente
        // convertiría al contrato anulado en «vigente».
        $currentId = $masters->first(
            static fn (CompanyContract $c): bool => ! $c->trashed(),
        )?->id;

        $entries = $masters->map(
            static fn (CompanyContract $c): ContractLedgerEntry => ContractLedgerEntry::signedMaster(
                $c,
                $c->id === $currentId,
            ),
        );

        if ($currentId === null) {
            $entries = $entries->prepend(ContractLedgerEntry::pendingMaster());
        }

        return $entries->values();
    }

    /**
     * @return Collection<int, ContractLedgerEntry>
     */
    private function addendumEntries(Company $company): Collection
    {
        /** @var Collection<int, CompanyContract> $signed */
        $signed = CompanyContract::acrossCompanies()
            ->withTrashed()
            ->where('company_id', $company->id)
            ->whereNotNull('vacancy_id')
            ->with(['signedBy', 'vacancy'])
            ->orderByDesc('signed_at')
            ->get();

        /** @var Collection<int, Vacancy> $pending */
        $pending = $company->vacancies()
            ->whereDoesntHave('signedAddendum')
            ->where(function ($q): void {
                $q->where('fee_percentage', '>', 0)
                    ->orWhere('fee_amount', '>', 0);
            })
            ->orderByDesc('id')
            ->get();

        return $signed
            ->map(static fn (CompanyContract $c): ContractLedgerEntry => ContractLedgerEntry::signedAddendum(
                $c,
                $c->vacancy,
            ))
            ->concat($pending->map(
                static fn (Vacancy $v): ContractLedgerEntry => ContractLedgerEntry::pendingAddendum($v),
            ))
            ->values();
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            ContractLedgerEntry::STATUS_PENDING => 0,
            ContractLedgerEntry::STATUS_SIGNED => 1,
            default => 2,
        };
    }
}
