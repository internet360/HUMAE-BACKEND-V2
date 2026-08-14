<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Contracts;

/**
 * Presentación de los términos comerciales de un contrato.
 *
 * Existe como lista blanca y no como `$terms` a secas por una razón concreta:
 * el snapshot guarda `signature_path`, que es una ruta del disco privado donde
 * vive la firma del apoderado de HUMAE. Devolver el arreglo entero la publicaría
 * en cada respuesta del historial.
 *
 * La lista blanca es la forma correcta de evitarlo: una lista negra se olvida el
 * día que `currentTerms()` agregue otro campo interno.
 */
final class ContractTerms
{
    /**
     * @param  array<string, mixed>|null  $terms
     * @return array<string, mixed>|null
     */
    public static function present(?array $terms): ?array
    {
        if ($terms === null) {
            return null;
        }

        $signatory = is_array($terms['signatory'] ?? null) ? $terms['signatory'] : [];

        return [
            'version' => $terms['version'] ?? null,
            'provider_name' => $terms['provider_name'] ?? null,
            'fee_kind' => $terms['fee_kind'] ?? null,
            'fee_value' => $terms['fee_value'] ?? null,
            'fee_amount_words' => $terms['fee_amount_words'] ?? null,
            'payment_days' => $terms['payment_days'] ?? null,
            'payment_day_kind' => $terms['payment_day_kind'] ?? null,
            'warranty_days' => $terms['warranty_days'] ?? null,
            'city' => $terms['city'] ?? null,
            'jurisdiction' => $terms['jurisdiction'] ?? null,
            'signatory' => [
                'name' => $signatory['name'] ?? null,
                'title' => $signatory['title'] ?? null,
            ],
        ];
    }
}
