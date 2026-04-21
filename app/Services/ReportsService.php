<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentStage;
use App\Enums\CandidateState;
use App\Enums\InterviewState;
use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Enums\VacancyState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportsService
{
    /**
     * @return array{total: int, by_day: list<array{date: string, count: int}>, by_state: array<string, int>}
     */
    public function candidatesRegistered(Carbon $from, Carbon $to): array
    {
        /** @var list<array{date: string, count: int}> $byDay */
        $byDay = array_values(DB::table('candidate_profiles')
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->whereBetween('created_at', [$from, $to])
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => ['date' => (string) $r->date, 'count' => (int) $r->count])
            ->all());

        $byState = DB::table('candidate_profiles')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('state')
            ->pluck(DB::raw('COUNT(*)'), 'state')
            ->map(fn ($v) => (int) $v)
            ->all();

        return [
            'total' => (int) array_sum($byState),
            'by_day' => $byDay,
            'by_state' => $byState,
        ];
    }

    /**
     * @return array{active: int, by_plan: array<string, int>, expiring_soon: int}
     */
    public function activeMemberships(): array
    {
        $active = DB::table('memberships')
            ->where('status', MembershipStatus::Active->value)
            ->where('expires_at', '>', now())
            ->count();

        $byPlan = DB::table('memberships')
            ->join('membership_plans', 'memberships.membership_plan_id', '=', 'membership_plans.id')
            ->where('memberships.status', MembershipStatus::Active->value)
            ->where('memberships.expires_at', '>', now())
            ->groupBy('membership_plans.code')
            ->pluck(DB::raw('COUNT(*)'), 'membership_plans.code')
            ->map(fn ($v) => (int) $v)
            ->all();

        $expiringSoon = DB::table('memberships')
            ->where('status', MembershipStatus::Active->value)
            ->whereBetween('expires_at', [now(), now()->addDays(30)])
            ->count();

        return [
            'active' => (int) $active,
            'by_plan' => $byPlan,
            'expiring_soon' => (int) $expiringSoon,
        ];
    }

    /**
     * @return array{total_paid: float, count_succeeded: int, count_failed: int, count_refunded: int, by_day: list<array{date: string, amount: float}>}
     */
    public function payments(Carbon $from, Carbon $to): array
    {
        $rawAggregates = DB::table('payments')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as sum_amount')
            ->groupBy('status')
            ->get();

        /** @var array<string, array{count: int, sum_amount: float}> $aggregates */
        $aggregates = [];
        foreach ($rawAggregates as $row) {
            $aggregates[(string) $row->status] = [
                'count' => (int) $row->count,
                'sum_amount' => (float) $row->sum_amount,
            ];
        }

        /** @var list<array{date: string, amount: float}> $byDay */
        $byDay = array_values(DB::table('payments')
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('DATE(paid_at) as date, COALESCE(SUM(amount), 0) as amount')
            ->groupByRaw('DATE(paid_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->date,
                'amount' => (float) $r->amount,
            ])
            ->all());

        return [
            'total_paid' => (float) ($aggregates[PaymentStatus::Succeeded->value]['sum_amount'] ?? 0),
            'count_succeeded' => (int) ($aggregates[PaymentStatus::Succeeded->value]['count'] ?? 0),
            'count_failed' => (int) ($aggregates[PaymentStatus::Failed->value]['count'] ?? 0),
            'count_refunded' => (int) ($aggregates[PaymentStatus::Refunded->value]['count'] ?? 0),
            'by_day' => $byDay,
        ];
    }

    /**
     * @return list<array{id: int, user_id: int, expires_at: string, days_left: int}>
     */
    public function expiringMemberships(int $days = 30): array
    {
        $from = now();
        $to = now()->addDays($days);

        /** @var list<array{id: int, user_id: int, expires_at: string, days_left: int}> $items */
        $items = array_values(DB::table('memberships')
            ->where('status', MembershipStatus::Active->value)
            ->whereBetween('expires_at', [$from, $to])
            ->orderBy('expires_at')
            ->get()
            ->map(function ($m) {
                $expiresAt = $m->expires_at !== null ? Carbon::parse((string) $m->expires_at) : null;

                return [
                    'id' => (int) $m->id,
                    'user_id' => (int) $m->user_id,
                    'expires_at' => $expiresAt?->toIso8601String() ?? '',
                    'days_left' => $expiresAt !== null
                        ? max(0, (int) now()->diffInDays($expiresAt, absolute: true))
                        : 0,
                ];
            })
            ->all());

        return $items;
    }

    /**
     * @return array<string, int>
     */
    public function vacanciesByState(): array
    {
        $counts = DB::table('vacancies')
            ->whereNull('deleted_at')
            ->groupBy('state')
            ->pluck(DB::raw('COUNT(*)'), 'state')
            ->map(fn ($v) => (int) $v)
            ->all();

        foreach (VacancyState::cases() as $state) {
            $counts[$state->value] = $counts[$state->value] ?? 0;
        }

        return $counts;
    }

    /**
     * @return array{total: int, by_state: array<string, int>, by_day: list<array{date: string, count: int}>}
     */
    public function interviews(Carbon $from, Carbon $to): array
    {
        $byState = DB::table('interviews')
            ->whereBetween('scheduled_at', [$from, $to])
            ->groupBy('state')
            ->pluck(DB::raw('COUNT(*)'), 'state')
            ->map(fn ($v) => (int) $v)
            ->all();

        foreach (InterviewState::cases() as $state) {
            $byState[$state->value] = $byState[$state->value] ?? 0;
        }

        /** @var list<array{date: string, count: int}> $byDay */
        $byDay = array_values(DB::table('interviews')
            ->whereBetween('scheduled_at', [$from, $to])
            ->selectRaw('DATE(scheduled_at) as date, COUNT(*) as count')
            ->groupByRaw('DATE(scheduled_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => ['date' => (string) $r->date, 'count' => (int) $r->count])
            ->all());

        return [
            'total' => (int) array_sum($byState),
            'by_state' => $byState,
            'by_day' => $byDay,
        ];
    }

    /**
     * @return list<array{recruiter_id: int, assignments: int, hired: int, rejected: int, hire_rate: float}>
     */
    public function recruiterEffectiveness(): array
    {
        /** @var list<array{recruiter_id: int, assignments: int, hired: int, rejected: int, hire_rate: float}> $items */
        $items = array_values(DB::table('vacancy_assignments')
            ->selectRaw('
                assigned_by as recruiter_id,
                COUNT(*) as assignments,
                SUM(CASE WHEN stage = ? THEN 1 ELSE 0 END) as hired,
                SUM(CASE WHEN stage = ? THEN 1 ELSE 0 END) as rejected
            ', [AssignmentStage::Hired->value, AssignmentStage::Rejected->value])
            ->whereNotNull('assigned_by')
            ->groupBy('assigned_by')
            ->get()
            ->map(function ($r) {
                $assignments = (int) $r->assignments;
                $hired = (int) $r->hired;

                return [
                    'recruiter_id' => (int) $r->recruiter_id,
                    'assignments' => $assignments,
                    'hired' => $hired,
                    'rejected' => (int) $r->rejected,
                    'hire_rate' => $assignments > 0
                        ? round($hired / $assignments, 3)
                        : 0.0,
                ];
            })
            ->all());

        return $items;
    }

    /**
     * @return array{count: int, average_days: float|null, median_days: float|null}
     */
    public function timeToFill(): array
    {
        $durationSql = DB::getDriverName() === 'sqlite'
            ? 'CAST(julianday(vacancy_assignments.hired_at) - julianday(vacancies.created_at) AS REAL) as days'
            : 'TIMESTAMPDIFF(DAY, vacancies.created_at, vacancy_assignments.hired_at) as days';

        $durations = DB::table('vacancy_assignments')
            ->join('vacancies', 'vacancy_assignments.vacancy_id', '=', 'vacancies.id')
            ->where('vacancy_assignments.stage', AssignmentStage::Hired->value)
            ->whereNotNull('vacancy_assignments.hired_at')
            ->selectRaw($durationSql)
            ->pluck('days')
            ->map(fn ($v) => (float) $v)
            ->filter(fn ($v) => $v >= 0)
            ->values()
            ->all();

        $count = count($durations);

        if ($count === 0) {
            return ['count' => 0, 'average_days' => null, 'median_days' => null];
        }

        sort($durations);
        $mid = (int) floor($count / 2);
        $median = $count % 2 === 0
            ? ($durations[$mid - 1] + $durations[$mid]) / 2
            : $durations[$mid];

        return [
            'count' => $count,
            'average_days' => round(array_sum($durations) / $count, 1),
            'median_days' => round($median, 1),
        ];
    }

    /**
     * @return list<array{candidate_profile_id: int, times_favorited: int, first_name: string|null, last_name: string|null}>
     */
    public function mostSearchedProfiles(int $limit = 20): array
    {
        /** @var list<array{candidate_profile_id: int, times_favorited: int, first_name: string|null, last_name: string|null}> $items */
        $items = array_values(DB::table('directory_favorites')
            ->join('candidate_profiles', 'directory_favorites.candidate_profile_id', '=', 'candidate_profiles.id')
            ->selectRaw('
                candidate_profiles.id as candidate_profile_id,
                candidate_profiles.first_name,
                candidate_profiles.last_name,
                COUNT(*) as times_favorited
            ')
            ->groupBy(
                'candidate_profiles.id',
                'candidate_profiles.first_name',
                'candidate_profiles.last_name',
            )
            ->orderByDesc('times_favorited')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'candidate_profile_id' => (int) $r->candidate_profile_id,
                'times_favorited' => (int) $r->times_favorited,
                'first_name' => $r->first_name !== null ? (string) $r->first_name : null,
                'last_name' => $r->last_name !== null ? (string) $r->last_name : null,
            ])
            ->all());

        return $items;
    }

    /**
     * @return list<string>
     */
    public static function candidateStates(): array
    {
        return array_map(fn (CandidateState $s) => $s->value, CandidateState::cases());
    }
}
