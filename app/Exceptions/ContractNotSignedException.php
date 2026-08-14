<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * La empresa no tiene contrato de prestación de servicios vigente.
 *
 * Excepción propia y no un `RuntimeException` suelto porque el frontend tiene
 * que distinguir este caso: no es un error del que agenda, es un trámite
 * pendiente de la empresa, y la pantalla correcta no es un toast rojo sino el
 * botón de firmar.
 */
class ContractNotSignedException extends RuntimeException
{
    public static function cannotScheduleInterview(): self
    {
        return new self(
            'La empresa no tiene contrato firmado. No se puede programar la entrevista hasta que su representante legal lo firme.',
        );
    }

    /**
     * Segundo candado: sin contrato no se devenga el cargo.
     *
     * El primero está en el agendado, y no basta — mover etapas y crear
     * entrevistas son operaciones distintas, así que se puede llegar a `hired`
     * respetando la máquina de estados sin haber agendado nunca. Facturar ahí
     * dejaría a HUMAE cobrando un servicio sin instrumento que lo sostenga.
     */
    public static function cannotAccrueCharge(): self
    {
        return new self(
            'La empresa no tiene contrato firmado. No se puede registrar el cargo por colocación sin instrumento que lo sostenga.',
        );
    }
}
