<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Autorización sobre los cargos por colocación.
 *
 * Sólo HUMAE los lee por ahora. La empresa verá su propio cargo cuando exista
 * la pantalla de cobranza del lado cliente; hasta entonces se le comunica por
 * factura, y abrir el endpoint «porque ya está» expondría la cartera antes de
 * que nadie decidiera cómo se le presenta.
 */
class PlacementChargePolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::Admin->value) ? true : null;
    }

    /**
     * No hay `view()`. El detalle de un cargo todavía no tiene endpoint, y la
     * convención del proyecto es no dejar abilities que nadie invoca: una regla
     * escrita y nunca ejercitada envejece sin que ningún test lo note. Cuando
     * exista la pantalla, la ability nace con ella.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }
}
