<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\User;

/**
 * Authorization over candidate records, including the private directory.
 *
 * ARCHITECTURE.md §5.5 scopes the directory to recruiter / admin and §6 spells
 * the individual rows out: "Ver directorio de candidatos: Empresa ❌", "Ver
 * expediente completo de candidato: Empresa ❌", "Descargar CV de cualquier
 * candidato: Empresa ❌", "Marcar favoritos: Empresa ❌".
 *
 * A client company never browses HUMAE's talent base. She sees only the
 * candidates HUMAE presented to her own vacancy, through the pipeline
 * (`GET /me/company/vacancies/{id}/assignments`). That boundary is the product
 * premise in §1: curation by HUMAE is what the fee pays for.
 */
class CandidateProfilePolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::Admin->value) ? true : null;
    }

    /**
     * Browse the directory listing (compact cards, no contact data, no files).
     * Internal staff only — see the class docblock.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    /**
     * Read the full record: CURP, RFC, address, contact phone, references and
     * document metadata. Internal staff, or the candidate itself.
     */
    public function view(User $user, CandidateProfile $profile): bool
    {
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        return $profile->user_id === $user->id;
    }

    /**
     * Read the psychometric results of a candidate.
     *
     * Internal staff only, and deliberately NOT folded into `view()`: the
     * expediente and the psychometric profile are different sensitivities. A
     * personality measurement says things about a person that a CV does not, so
     * it gets its own ability and can be narrowed later (e.g. only the recruiter
     * assigned to the vacancy) without touching the rest of the directory.
     *
     * The candidate reads their own results through
     * `/me/psychometrics/results/{attempt}`, not here.
     *
     * The company client never reaches this ability: it sees results only for
     * candidates HUMAE presented to it, via
     * `VacancyAssignmentPolicy::viewPsychometrics()`.
     */
    public function viewPsychometrics(User $user, CandidateProfile $profile): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    /**
     * Anotar la interpretación de un resultado psicométrico.
     *
     * Ability propia y no `viewPsychometrics`: leer una medición y dejar escrito
     * un juicio sobre la persona son actos distintos, y el segundo queda firmado
     * con quién lo hizo. Separarlos permite además abrir la lectura a más gente
     * sin abrir la escritura.
     *
     * Sólo HUMAE. La empresa cliente ni ve estas notas: su recurso las excluye.
     */
    public function reviewPsychometrics(User $user, CandidateProfile $profile): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    /**
     * Download the generated CV of an arbitrary candidate. Internal staff only.
     */
    public function downloadCv(User $user, CandidateProfile $profile): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    /**
     * Download a document a candidate uploaded to the private disk.
     * Internal staff only.
     */
    public function downloadDocument(User $user, CandidateProfile $profile): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    /**
     * Bookmark a candidate. `directory_favorites` is keyed by `recruiter_id`,
     * so this is staff-only by schema as well as by §6.
     */
    public function favorite(User $user, CandidateProfile $profile): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }
}
