<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Contracts;

use App\Support\Contracts\ContractLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una línea del historial: un instrumento firmado, anulado o todavía por firmar.
 *
 * Las tres formas comparten el mismo shape a propósito. El frontend pinta una
 * sola lista, y una entrada pendiente que llegara con otra estructura obligaría
 * a la vista a decidir en tiempo de render cuál de dos componentes usar — que es
 * justo donde se cuelan los estados que nadie diseñó.
 *
 * @property-read ContractLedgerEntry $resource
 */
class ContractLedgerEntryResource extends JsonResource
{
    /**
     * @param  array{terms: array<string, mixed>|null, blocker: string|null}  $pending
     *                                                                                  qué se firmaría en una entrada pendiente, o por qué
     *                                                                                  hoy no se puede emitir
     */
    public function __construct(
        ContractLedgerEntry $resource,
        private readonly array $pending = ['terms' => null, 'blocker' => null],
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            'key' => $entry->key,
            'kind' => $entry->kind,
            'status' => $entry->status,
            'title' => $entry->title,
            'is_current' => $entry->isCurrent,

            'vacancy' => $entry->vacancy === null ? null : [
                'id' => $entry->vacancy->id,
                'title' => $entry->vacancy->title,
            ],

            'contract' => $entry->contract === null
                ? null
                : StaffContractResource::make($entry->contract)->resolve($request),

            /*
             * Sólo en las pendientes. `terms` es lo que la empresa firmaría hoy
             * —el número que ya se le propuso—, y `blocker` el motivo por el que
             * no puede: faltan condiciones comerciales, o la vacante perdió su
             * honorario propio. Sin esto, una fila «pendiente» que en realidad
             * es inemitible se ve igual que una que sólo espera una firma.
             */
            'pending_terms' => ContractTerms::present($this->pending['terms']),
            'blocker' => $this->pending['blocker'],
        ];
    }
}
