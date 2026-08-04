<?php

declare(strict_types=1);

namespace App\Models\Contracts;

use App\Models\Concerns\BelongsToCompany;

/**
 * A record that belongs to a client company.
 *
 * Implemented through {@see BelongsToCompany}, which also
 * installs the tenancy scope. The interface exists so the scope can recognise
 * the model without an unresolvable trait intersection.
 */
interface CompanyOwned
{
    /**
     * The column holding the owning company id.
     */
    public function companyOwnerKeyName(): string;
}
