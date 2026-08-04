<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\UserRole;
use App\Models\Scopes\CompanyOwnedScope;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The single answer to "which companies does this caller belong to, and does
 * this record belong to one of them?".
 *
 * Before this class every endpoint re-derived tenancy by hand — a
 * `companyMemberships()->pluck()` here, a `members()->exists()` there, an
 * `exists:companies,id` rule that checks the row is real but never that it is
 * yours. Six of the sixteen audit findings (F-01, F-02, F-04, F-05, F-06,
 * F-07) are that same omission repeated, so the derivation lives in exactly
 * one place now.
 *
 * Two consumers sit on top of it:
 *
 *  - {@see CompanyOwnedScope}, the global scope on
 *    company-owned models. It answers the read half.
 *  - the `RestrictsFieldsByRole` form-request concern, which rejects a payload
 *    naming a company the caller does not belong to. It answers the write half.
 *
 * HUMAE staff (recruiter, admin) are not tenants: they operate across every
 * client company by design (ARCHITECTURE.md §5.6), so `visibleCompanyIds()`
 * reports "unrestricted" for them. Everybody else is restricted to the
 * companies they are a member of — which for a candidate is the empty set, and
 * the empty set means nothing, not everything.
 */
final class CompanyTenancy
{
    /**
     * Memoised per user id. Membership does not change mid-request except
     * through the team endpoints, which flush the cache on write.
     *
     * @var array<int, list<int>>
     */
    private array $membershipCache = [];

    /**
     * The caller the current request is acting as, or null outside an
     * authenticated request (console commands, queued jobs, seeders).
     */
    public function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * HUMAE's own team. Not a tenant: it sees every client company.
     */
    public function isStaff(?User $user): bool
    {
        return $user !== null
            && $user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value]);
    }

    /**
     * The companies a user is a member of.
     *
     * Reads through the query builder on purpose: going through the
     * `CompanyMember` model would re-enter the global scope, which asks this
     * very method for the answer.
     *
     * @return list<int>
     */
    public function companyIdsFor(User $user): array
    {
        $id = (int) $user->getKey();

        if (! array_key_exists($id, $this->membershipCache)) {
            /** @var list<int> $ids */
            $ids = DB::table('company_members')
                ->where('user_id', $id)
                ->pluck('company_id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->unique()
                ->values()
                ->all();

            $this->membershipCache[$id] = $ids;
        }

        return $this->membershipCache[$id];
    }

    public function belongsTo(?User $user, int $companyId): bool
    {
        return $user !== null && in_array($companyId, $this->companyIdsFor($user), true);
    }

    /**
     * The company ids the current caller may read, or null when the caller is
     * not restricted at all (no caller, or HUMAE staff).
     *
     * An empty list is a real answer and means "no rows".
     *
     * @return list<int>|null
     */
    public function visibleCompanyIds(): ?array
    {
        $user = $this->actor();

        if ($user === null || $this->isStaff($user)) {
            return null;
        }

        return $this->companyIdsFor($user);
    }

    /**
     * Guard a caller-supplied company id. `exists:companies,id` proves the row
     * is real; this proves it is yours (F-02).
     *
     * @throws AuthorizationException
     */
    public function assertBelongsTo(?User $user, int $companyId, string $field = 'company_id'): void
    {
        if ($this->isStaff($user) || $this->belongsTo($user, $companyId)) {
            return;
        }

        throw new AuthorizationException(
            "No perteneces a la empresa indicada en «{$field}»."
        );
    }

    /**
     * Drop the memoised membership of a user, or of everybody when no user is
     * given. Called whenever a `company_members` row is written.
     */
    public function flush(?int $userId = null): void
    {
        if ($userId === null) {
            $this->membershipCache = [];

            return;
        }

        unset($this->membershipCache[$userId]);
    }
}
