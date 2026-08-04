<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\CompanyOwnedScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks a model as owned by a client company and puts it behind the tenancy
 * scope.
 *
 * Three models carry it today: `Company` (owned by itself, hence the `id`
 * override), `Vacancy` and `CompanyMember`.
 *
 * Escaping the scope is possible but has to be spelled out — `acrossCompanies()`
 * on a query root, or `->withoutGlobalScope(CompanyOwnedScope::class)` on a
 * relation that traverses from an already-authorized parent. Both are
 * deliberately loud: the unqualified call is the safe one, and a reviewer sees
 * every exception as an explicit line of code.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyOwnedScope);
    }

    /**
     * The column holding the owning company id.
     */
    public function companyOwnerKeyName(): string
    {
        return 'company_id';
    }

    /**
     * A query over every tenant. For work that is genuinely cross-company:
     * generating a unique slug, allocating the next vacancy code, HUMAE-wide
     * reporting.
     *
     * @return Builder<static>
     */
    public static function acrossCompanies(): Builder
    {
        return static::query()->withoutGlobalScope(CompanyOwnedScope::class);
    }
}
