<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;
use App\Support\Tenancy\CompanyTenancy;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Declares, per resource, which fields a caller's role may submit at all.
 *
 * Field-level permission used to be spread across three layers that did not
 * know about each other: `prohibited` rules in one Form Request, silent
 * `unset()` calls in a controller, and `when()` guards in the API Resource that
 * only cover reads. `ScheduleInterviewRequest` knew a client company may not
 * set `meeting_url`; `UpdateInterviewRequest` did not — same resource, same
 * rule, one of them written and the other forgotten. That is F-08, and F-04
 * through F-07 are the same shape.
 *
 * A Request using this concern answers the question once:
 *
 *   protected function staffOnlyFields(): array   — HUMAE writes these
 *   protected function companyScopedFields(): array — must name your company
 *
 * The refusal is a 403, not a 422, on purpose. "This field is not yours to
 * write" is an authorization decision, and answering it as a validation error
 * would tell the caller to fix its payload when the answer is that its role is
 * wrong. The check runs in `authorize()`, before any rule executes, so a
 * forbidden field is refused whether or not the rest of the payload is valid.
 */
trait RestrictsFieldsByRole
{
    /**
     * Fields only HUMAE staff (recruiter, admin) may submit.
     *
     * @return list<string>
     */
    protected function staffOnlyFields(): array
    {
        return [];
    }

    /**
     * Fields carrying a company id the caller must belong to.
     *
     * `exists:companies,id` proves the row is real; nothing proved it was
     * yours, which is how a client company created vacancies inside another
     * client's account (F-02).
     *
     * @return list<string>
     */
    protected function companyScopedFields(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        $this->guardStaffOnlyFields();
        $this->guardCompanyScopedFields();

        return true;
    }

    /**
     * @throws AuthorizationException
     */
    protected function guardStaffOnlyFields(): void
    {
        $fields = $this->staffOnlyFields();

        if ($fields === [] || app(CompanyTenancy::class)->isStaff($this->callerAsUser())) {
            return;
        }

        $submitted = array_values(array_filter(
            $fields,
            fn (string $field): bool => $this->has($field),
        ));

        if ($submitted === []) {
            return;
        }

        throw new AuthorizationException(
            'Estos campos solo los edita el equipo de HUMAE: '.implode(', ', $submitted).'.'
        );
    }

    /**
     * @throws AuthorizationException
     */
    protected function guardCompanyScopedFields(): void
    {
        $tenancy = app(CompanyTenancy::class);

        foreach ($this->companyScopedFields() as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            // A non-numeric value is a shape problem; let the rules answer it
            // with a 422 instead of pretending it is an authorization decision.
            if (! is_numeric($value)) {
                continue;
            }

            $tenancy->assertBelongsTo($this->callerAsUser(), (int) $value, $field);
        }
    }

    private function callerAsUser(): ?User
    {
        $user = $this->user();

        return $user instanceof User ? $user : null;
    }
}
