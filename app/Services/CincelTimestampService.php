<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CincelTimestampException;
use Illuminate\Support\Facades\Http;

/**
 * Constancia de conservación de mensajes de datos NOM-151-SCFI-2016 emitida por
 * CINCEL.
 *
 * Ojo con lo que este servicio NO hace: CINCEL aquí no emite la firma. La firma
 * es el trazo que la persona dibuja y que se incrusta en el PDF; esto sella la
 * *integridad* del archivo resultante devolviendo un token de estampado de
 * tiempo (RFC 3161, formato ASN.1). El par firma-simple + constancia es lo que
 * sostiene el documento frente a los arts. 89-99 del Código de Comercio.
 *
 * Puerto del `CincelService` de RED1A1 (`src/services/cincel.service.ts`),
 * mismos parámetros de reintento para que ambos sistemas se comporten igual.
 */
class CincelTimestampService
{
    /**
     * Pide la constancia para un hash. La API responde 202 mientras la prepara,
     * así que se reintenta hasta agotar `max_retries`.
     *
     * @return string bytes crudos del token ASN.1
     *
     * @throws CincelTimestampException
     */
    public function fetch(string $hash): string
    {
        $config = config('services.cincel');

        $jwt = is_array($config) ? ($config['jwt'] ?? null) : null;
        if (! is_string($jwt) || $jwt === '') {
            throw CincelTimestampException::notConfigured();
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.cincel.digital/v3'), '/');
        $maxRetries = max(1, (int) ($config['max_retries'] ?? 5));
        $delayMs = max(0, (int) ($config['retry_delay_ms'] ?? 1500));
        $timeout = max(1, (int) ($config['timeout_seconds'] ?? 20));

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $response = Http::withToken($jwt)
                ->accept('application/octet-stream')
                ->timeout($timeout)
                ->get("{$baseUrl}/timestamps/{$hash}.asn1");

            if ($response->status() === 202) {
                // Constancia en preparación. No dormimos tras el último intento.
                if ($attempt < $maxRetries && $delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                continue;
            }

            if ($response->successful()) {
                $body = $response->body();

                // Un 200 con cuerpo vacío también significa "todavía no está":
                // es el mismo caso que el 202 en la implementación de RED1A1.
                if ($body !== '') {
                    return $body;
                }

                if ($attempt < $maxRetries && $delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                continue;
            }

            throw CincelTimestampException::requestFailed($response->status(), $response->body());
        }

        throw CincelTimestampException::timedOut($maxRetries);
    }
}
