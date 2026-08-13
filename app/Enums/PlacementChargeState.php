<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de cobranza de un cargo por colocación.
 *
 * El sistema sólo DEVENGA: registra que el cargo existe y con qué números. La
 * facturación CFDI y el cobro viven fuera, así que estos estados son el reflejo
 * de un trámite externo, no su motor. Por eso `facturada` y `cobrada` las marca
 * una persona y no un webhook.
 */
enum PlacementChargeState: string
{
    /** Devengado. Existe la obligación; no hay CFDI todavía. */
    case PorFacturar = 'por_facturar';

    case Facturada = 'facturada';

    case Cobrada = 'cobrada';

    /** Se canceló la colocación o se renegoció a cero. Queda el rastro. */
    case Cancelada = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::PorFacturar => 'Por facturar',
            self::Facturada => 'Facturada',
            self::Cobrada => 'Cobrada',
            self::Cancelada => 'Cancelada',
        };
    }
}
