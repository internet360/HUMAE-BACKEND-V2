<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Falla al obtener la constancia NOM-151 de CINCEL.
 *
 * No aborta la firma: `CompanyContractService` la captura y deja el contrato
 * firmado con `timestamp_path` en NULL para reintentar el sello después. Perder
 * la firma de la empresa porque un tercero está caído sería el peor de los
 * comportamientos posibles.
 */
class CincelTimestampException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('CINCEL no está configurado: falta CINCEL_JWT.');
    }

    public static function requestFailed(int $status, string $body): self
    {
        return new self(sprintf(
            'CINCEL respondió %d al pedir la constancia: %s',
            $status,
            mb_substr($body, 0, 300),
        ));
    }

    public static function timedOut(int $attempts): self
    {
        return new self(sprintf(
            'CINCEL no entregó la constancia después de %d intentos.',
            $attempts,
        ));
    }
}
