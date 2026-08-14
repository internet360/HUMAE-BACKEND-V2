<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Contracts;

use App\Models\CompanyContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Un contrato firmado, visto por HUMAE.
 *
 * Es deliberadamente más ancha que `CompanyContractResource`, que es la que ve
 * la empresa. La empresa necesita saber que firmó; HUMAE necesita poder
 * demostrarlo: la huella del PDF, la IP y el agente desde donde se firmó, los
 * dos consentimientos con su hora, y el acceso a la identificación y la selfie
 * que acreditan a quien firmó.
 *
 * Lo que NO expone son rutas del disco. Cada archivo viaja como una URL a un
 * endpoint autenticado que valida la policy en cada descarga; una ruta de disco
 * en el JSON es una ruta que alguien va a intentar servir por otro camino.
 *
 * @mixin CompanyContract
 */
class StaffContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CompanyContract $contract */
        $contract = $this->resource;

        return [
            'id' => $contract->id,
            'folio' => $contract->folio,
            'is_addendum' => $contract->isAddendum(),
            'is_voided' => $contract->trashed(),
            'voided_at' => $contract->deleted_at?->toIso8601String(),

            'company' => [
                'id' => $contract->company_id,
                'legal_name' => $contract->company?->legal_name,
                'trade_name' => $contract->company?->trade_name,
            ],

            'vacancy' => $contract->vacancy === null ? null : [
                'id' => $contract->vacancy->id,
                'title' => $contract->vacancy->title,
            ],

            'signer' => [
                'user_id' => $contract->signed_by_user_id,
                'name' => $contract->signedBy?->name,
                'email' => $contract->signedBy?->email,
                'title' => $contract->signer_title,
            ],

            'signed_at' => $contract->signed_at->toIso8601String(),
            'terms_accepted_at' => $contract->terms_accepted_at?->toIso8601String(),
            'privacy_accepted_at' => $contract->privacy_accepted_at?->toIso8601String(),

            /*
             * Evidencia de la firma electrónica simple (arts. 89-99 CCom). Es
             * lo que convierte «dice que firmó» en «firmó desde esta IP, con
             * este navegador, a esta hora».
             */
            'evidence' => [
                'ip' => $contract->signed_ip,
                'user_agent' => $contract->signed_user_agent,
            ],

            'integrity' => [
                'pdf_hash' => $contract->pdf_hash,
                'is_timestamped' => $contract->isTimestamped(),
                'timestamped_at' => $contract->timestamped_at?->toIso8601String(),
            ],

            'terms' => ContractTerms::present($contract->terms),

            /*
             * Qué archivos tiene realmente este contrato. No viajan URLs: el
             * cliente arma la ruta con el id (`/contracts/{id}/files/{kind}`),
             * igual que ya hace con la descarga del contrato de la empresa.
             *
             * Lo que sí importa es el booleano. Un contrato de hace dos años
             * cuyo PDF se perdió en una migración se ve idéntico a uno íntegro
             * si sólo mandamos el enlace, y el problema se descubre el día que
             * alguien lo necesita para cobrar. Mejor que el historial lo diga
             * solo, antes de que nadie haga clic.
             */
            'available_files' => [
                'pdf' => $this->exists($contract->pdf_path),
                'identity' => $this->exists($contract->identity_path),
                'selfie' => $this->exists($contract->selfie_path),
                'signature' => $this->exists($contract->signature_path),
                'timestamp' => $this->exists($contract->timestamp_path),
            ],
        ];
    }

    private function exists(?string $path): bool
    {
        return is_string($path)
            && $path !== ''
            && Storage::disk('local')->exists($path);
    }
}
