<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Companies;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'slug' => $this->slug,
            'rfc' => $this->rfc,
            'description' => $this->description,
            'website' => $this->website,
            'logo_url' => $this->logo_url,
            'cover_url' => $this->cover_url,
            'industry_id' => $this->industry_id,
            'company_size_id' => $this->company_size_id,
            'ownership_type_id' => $this->ownership_type_id,
            'founded_year' => $this->founded_year,
            'contact' => [
                'name' => $this->contact_name,
                'email' => $this->contact_email,
                'phone' => $this->contact_phone,
                'position' => $this->contact_position,
            ],
            'location' => [
                'country_id' => $this->country_id,
                'state_id' => $this->state_id,
                'city_id' => $this->city_id,
                'address_line' => $this->address_line,
                'postal_code' => $this->postal_code,
            ],
            'socials' => [
                'linkedin' => $this->linkedin_url,
                'facebook' => $this->facebook_url,
                'instagram' => $this->instagram_url,
                'twitter' => $this->twitter_url,
            ],
            'status' => $this->status,
            'is_verified' => $this->is_verified,
            'account_manager_id' => $this->account_manager_id,
            'members' => CompanyMemberResource::collection(
                $this->whenLoaded('members'),
            ),
            /*
             * Presente sólo si el consumidor precargó `latestContract`. El gate
             * del frontend lo lee de aquí para no pedir un segundo request en
             * cada carga del área de empresa.
             *
             * Va con `when(relationLoaded(...))` y no con `whenLoaded()`: cuando
             * la relación está cargada pero vacía —justo el caso de la empresa
             * que no ha firmado— `whenLoaded()` devuelve null sin llamar al
             * callback, y el gate recibiría `contract: null` en vez de
             * `is_signed: false`.
             */
            'contract' => $this->when(
                $this->resource->relationLoaded('latestContract'),
                fn () => [
                    ...($this->latestContract === null
                        ? ['is_signed' => false]
                        : [
                            'is_signed' => true,
                            'folio' => $this->latestContract->folio,
                            'signed_at' => $this->latestContract->signed_at->toIso8601String(),
                            'is_timestamped' => $this->latestContract->isTimestamped(),
                        ]),
                    /*
                     * Adendas de honorarios propuestas y sin firmar. Viaja junto
                     * al estado del maestro porque la pregunta del listado es
                     * una sola —«¿este cliente tiene algo sin firmar?»— y
                     * separarlas obligaría a la tarjeta a componer dos fuentes
                     * para decidir un único badge.
                     *
                     * Sólo si quien consulta pidió el conteo: sin él la clave no
                     * aparece, en vez de aparecer en cero y mentir.
                     */
                    ...(($count = $this->resource->getAttribute('pending_addenda_count')) === null
                        ? []
                        : ['pending_addenda' => (int) $count]),
                ],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
