<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Tenancy\CompanyTenancy;
use Illuminate\Support\Facades\DB;

/**
 * What slice of the reporting data a caller is entitled to.
 *
 * ARCHITECTURE.md §6 grants "Ver reportes" to three roles with three different
 * scopes — "Reclutador ✅ (sus procesos), Empresa cliente ✅ (sus vacantes),
 * Admin ✅ (todos)" — while §5.10 files the whole section under "Admin (admin
 * only)". The product owner resolved that in favour of §6, and the
 * implementation honoured neither: recruiters received global aggregates and
 * client companies received a flat 403 (F-11).
 *
 * The nine reports do not all admit a per-role slice, so they split in three
 * families:
 *
 * - **Process** — dimensioned by vacancy, which is exactly what "sus procesos"
 *   and "sus vacantes" refer to: `vacancies-by-state`, `interviews`,
 *   `time-to-fill`. Every granted role reads them, narrowed to its own slice.
 * - **Recruiter performance** — `recruiter-effectiveness`. Vacancy-shaped, but
 *   it measures HUMAE's own team, so a client does not read it. A recruiter
 *   sees his own row; an admin sees everyone.
 * - **Platform** — HUMAE's business metrics: candidate registrations,
 *   memberships, payments, and the directory's most favourited profiles. A
 *   payment has no vacancy, so "sus vacantes" cannot narrow it, and §6 closes
 *   the whole candidate axis to the client company — the directory explicitly
 *   ("Ver directorio de candidatos: Empresa cliente ❌"). HUMAE only.
 */
final readonly class ReportScope
{
    /**
     * @param  list<int>|null  $vacancyIds  null means every vacancy.
     * @param  list<int>|null  $recruiterIds  null means every recruiter.
     */
    private function __construct(
        public ?array $vacancyIds,
        public ?array $recruiterIds,
        public bool $seesProcessReports,
        public bool $seesRecruiterPerformance,
        public bool $seesPlatformMetrics,
    ) {}

    public static function forUser(User $user): self
    {
        if ($user->hasRole(UserRole::Admin->value)) {
            return new self(null, null, true, true, true);
        }

        if ($user->hasRole(UserRole::Recruiter->value)) {
            // "Sus procesos" is read as the vacancies HUMAE put him in charge
            // of. It is the narrowest definition the document supports without
            // inventing one; if the product owner means something wider, this
            // query is the only thing that changes.
            return new self(
                vacancyIds: self::vacancyIdsAssignedTo((int) $user->getKey()),
                recruiterIds: [(int) $user->getKey()],
                seesProcessReports: true,
                seesRecruiterPerformance: true,
                seesPlatformMetrics: true,
            );
        }

        if ($user->hasRole(UserRole::CompanyUser->value)) {
            return new self(
                vacancyIds: self::vacancyIdsOwnedBy(app(CompanyTenancy::class)->companyIdsFor($user)),
                recruiterIds: [],
                seesProcessReports: true,
                seesRecruiterPerformance: false,
                seesPlatformMetrics: false,
            );
        }

        // §6 — "Ver reportes: Candidato ❌".
        return new self([], [], false, false, false);
    }

    /**
     * True when the caller reads the whole platform: only the admin.
     */
    public function isGlobal(): bool
    {
        return $this->vacancyIds === null && $this->recruiterIds === null;
    }

    /**
     * @return list<int>
     */
    private static function vacancyIdsAssignedTo(int $recruiterId): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('vacancies')
            ->whereNull('deleted_at')
            ->where('assigned_recruiter_id', $recruiterId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<int>
     */
    private static function vacancyIdsOwnedBy(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = DB::table('vacancies')
            ->whereNull('deleted_at')
            ->whereIn('company_id', $companyIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }
}
