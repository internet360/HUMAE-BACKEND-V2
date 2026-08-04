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

    public function update(User $user, CandidateProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }

    public function delete(User $user, CandidateProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }
}
