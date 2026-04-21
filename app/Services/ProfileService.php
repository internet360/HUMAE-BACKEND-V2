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
        $profile->fill($data)->save();

        return $profile->fresh() ?? $profile;
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
