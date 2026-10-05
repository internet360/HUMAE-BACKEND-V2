<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InvoiceRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Only the owner reads their request; whoever holds `invoices.manage` reads everything. Ability names
 * must not collide with permission names (Spatie's Gate::before would
 * auto-approve them).
 */
class InvoiceRequestPolicy
{
    public function before(User $user): ?bool
    {
        return $user->checkPermissionTo('invoices.manage') ? true : null;
    }

    /**
     * A foreign request answers 404, same as a missing id, so ids cannot be
     * enumerated.
     */
    public function view(User $user, InvoiceRequest $request): Response
    {
        return $request->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
