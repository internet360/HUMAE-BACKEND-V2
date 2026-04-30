<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CandidateState;
use App\Models\CandidateProfile;
use App\Models\User;

class ProfileService
{
    /**
     * Devuelve el perfil del candidato autenticado, creándolo vacío si aún no existe.
     * Consulta sin usar la relación cacheada en memoria para evitar stale reads
     * entre requests sucesivos en tests o dentro del mismo ciclo de vida.
     */
    public function findOrCreate(User $user): CandidateProfile
    {
        $profile = CandidateProfile::where('user_id', $user->id)->first();

        if ($profile !== null) {
            return $profile;
        }

        return CandidateProfile::create([
            'user_id' => $user->id,
            'first_name' => $this->firstName($user),
            'last_name' => $this->lastName($user),
            'state' => CandidateState::RegistroIncompleto->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CandidateProfile $profile, array $data): CandidateProfile
    {
        $functionalAreas = $data['functional_areas'] ?? null;
        unset($data['functional_areas']);

        $profile->fill($data)->save();

        if (is_array($functionalAreas)) {
            $this->syncFunctionalAreas($profile, $functionalAreas);
        }

        return $profile->fresh() ?? $profile;
    }

    /**
     * Sincroniza las áreas de interés del candidato (PDF cosasfaltanteshumae,
     * punto 1: selección múltiple con marca de "principal"). Además mantiene
     * en sync el campo legacy `functional_area_id` con el área marcada como
     * primaria para compatibilidad con búsquedas existentes.
     *
     * @param  array<int, array{id: int, is_primary?: bool}>  $items
     */
    public function syncFunctionalAreas(CandidateProfile $profile, array $items): void
    {
        $sync = [];
        $primaryId = null;
        $sort = 0;

        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $isPrimary = (bool) ($item['is_primary'] ?? false);

            // Solo respetamos la primera marca de primary; el resto se ignoran
            // como primarias (validación de UI: máximo una principal).
            $resolvedPrimary = $isPrimary && $primaryId === null;
            if ($resolvedPrimary) {
                $primaryId = $id;
            }

            $sync[$id] = [
                'is_primary' => $resolvedPrimary,
                'sort_order' => $sort++,
            ];
        }

        $profile->functionalAreas()->sync($sync);

        // Mantener el campo legacy `functional_area_id` apuntando a la primary
        // (o a la primera si no hay primary explícita).
        $legacyId = $primaryId ?? array_key_first($sync) ?? null;
        if ($profile->functional_area_id !== $legacyId) {
            $profile->forceFill(['functional_area_id' => $legacyId])->save();
        }
    }

    private function firstName(User $user): string
    {
        $parts = preg_split('/\s+/', trim($user->name)) ?: [];

        return (string) ($parts[0] ?? $user->name);
    }

    private function lastName(User $user): string
    {
        $parts = preg_split('/\s+/', trim($user->name)) ?: [];

        if (count($parts) <= 1) {
            return '';
        }

        return (string) end($parts);
    }
}
