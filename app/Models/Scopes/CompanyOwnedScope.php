<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\Contracts\CompanyOwned;
use App\Support\Tenancy\CompanyTenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows every query rooted on a company-owned model to the companies the
 * caller belongs to.
 *
 * The point of making it global is the failure mode. An opt-in helper is only
 * as good as the developer who remembers to call it, and the audit showed what
 * happens when one does not: `GET /companies` handed the whole client roster to
 * any company user (F-01) because nobody wrote the filter. With the scope
 * global, a forgotten filter yields nothing instead of everything.
 *
 * It applies to queries the model itself starts — `Vacancy::query()`, route
 * model binding, `Company::find()`. It deliberately does NOT apply to the
 * handful of relations that opt out explicitly: reaching `$assignment->vacancy`
 * means an authorization check on the assignment already passed, and re-asking
 * the tenancy question there would break legitimate cross-boundary reads (a
 * candidate seeing the title of the vacancy he is being interviewed for, HUMAE
 * notifying the client company of a candidate's answer).
 */
final class CompanyOwnedScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! $model instanceof CompanyOwned) {
            return;
        }

        $visible = app(CompanyTenancy::class)->visibleCompanyIds();

        if ($visible === null) {
            return;
        }

        $column = $model->qualifyColumn($model->companyOwnerKeyName());

        if ($visible === []) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($column, $visible);
    }
}
