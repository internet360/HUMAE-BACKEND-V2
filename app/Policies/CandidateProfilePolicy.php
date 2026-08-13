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
 * Those rows still hold: a client company never reaches the internal directory.
 * What changed is that it now browses an ANONYMOUS preview of the talent base
 * through `viewAnonymousDirectory()` — a professional silhouette with no
 * identity and no files — so it can pick who it wants to meet before there is a
 * vacancy. Identity, CV, documents and psychometrics are revealed by HUMAE when
 * the interview is confirmed.
 *
 * The premise in §1 is intact and this is precisely how: curation is what the
 * fee pays for, and a company that cannot identify a candidate cannot go around
 * HUMAE to hire them. The two surfaces are different endpoints on purpose —
 * `tests/Feature/Security/CompanyUserDirectoryAccessTest.php` guards the
 * internal one and must stay green.
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
     * Browse the anonymous preview of the talent base.
     *
     * A separate ability from `viewAny()`, not a relaxation of it, and the
     * difference is the whole point. `viewAny()` opens the internal directory:
     * names, contact data, CV, documents, psychometrics. This one opens a
     * professional silhouette — role, area, seniority, city, expected range,
     * skills — addressed by an opaque reference, with no way to reach a file.
     *
     * The client company gets this one so it can pick who it wants to meet. It
     * still never gets `viewAny()`: identity is revealed by HUMAE when the
     * interview is confirmed, which is what keeps the intermediation —and the
     * placement fee that pays for it— from being trivially bypassed.
     */
    public function viewAnonymousDirectory(User $user): bool
    {
        return $user->hasRole(UserRole::CompanyUser->value);
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
