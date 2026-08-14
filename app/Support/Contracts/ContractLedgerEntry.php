<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use App\Models\CompanyContract;
use App\Models\Vacancy;

/**
 * Una línea del historial de contratos de una empresa.
 *
 * Existe porque la pregunta que el equipo hace —«¿qué le pedimos firmar a esta
 * empresa y qué falta?»— no se contesta leyendo `company_contracts`. Esa tabla
 * sólo tiene lo firmado. Lo pendiente vive en la ausencia: una empresa sin
 * contrato maestro, una vacante con honorario propio sin adenda. Un listado que
 * sólo muestre filas existentes contesta la mitad y la mitad que contesta es la
 * tranquilizadora.
 *
 * Por eso la entrada unifica los dos casos bajo un mismo `status`: firmado,
 * pendiente o anulado. Quien la lee no tiene que saber cuál salió de una fila y
 * cuál de una ausencia.
 */
final readonly class ContractLedgerEntry
{
    public const STATUS_SIGNED = 'signed';

    public const STATUS_PENDING = 'pending';

    /** Anulado por HUMAE. Conserva su evidencia; deja de sostener un cobro. */
    public const STATUS_VOIDED = 'voided';

    public const KIND_MASTER = 'master';

    public const KIND_ADDENDUM = 'addendum';

    /**
     * @param  string  $key  identificador estable para el cliente (no es un id de fila:
     *                       las entradas pendientes no tienen fila)
     * @param  bool  $isCurrent  el instrumento que rige hoy para su alcance. Un maestro
     *                           reemplazado por una renegociación sigue en el historial,
     *                           pero ya no gobierna nada.
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $status,
        public string $title,
        public bool $isCurrent,
        public ?CompanyContract $contract = null,
        public ?Vacancy $vacancy = null,
    ) {}

    public static function signedMaster(CompanyContract $contract, bool $isCurrent): self
    {
        return new self(
            key: 'contract-'.$contract->id,
            kind: self::KIND_MASTER,
            status: $contract->trashed() ? self::STATUS_VOIDED : self::STATUS_SIGNED,
            title: 'Acceso a la plataforma',
            isCurrent: $isCurrent && ! $contract->trashed(),
            contract: $contract,
        );
    }

    public static function pendingMaster(): self
    {
        return new self(
            key: 'pending-master',
            kind: self::KIND_MASTER,
            status: self::STATUS_PENDING,
            title: 'Acceso a la plataforma',
            isCurrent: true,
        );
    }

    public static function signedAddendum(CompanyContract $contract, ?Vacancy $vacancy): self
    {
        $title = $vacancy !== null
            ? 'Honorarios · '.$vacancy->title
            : 'Honorarios de vacante';

        return new self(
            key: 'contract-'.$contract->id,
            kind: self::KIND_ADDENDUM,
            status: $contract->trashed() ? self::STATUS_VOIDED : self::STATUS_SIGNED,
            title: $title,
            isCurrent: ! $contract->trashed(),
            contract: $contract,
            vacancy: $vacancy,
        );
    }

    public static function pendingAddendum(Vacancy $vacancy): self
    {
        return new self(
            key: 'pending-vacancy-'.$vacancy->id,
            kind: self::KIND_ADDENDUM,
            status: self::STATUS_PENDING,
            title: 'Honorarios · '.$vacancy->title,
            isCurrent: true,
            vacancy: $vacancy,
        );
    }
}
