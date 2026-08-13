<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Directory;

use App\Models\CandidateProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CandidateProfile
 *
 * La silueta profesional de un candidato, sin identidad.
 *
 * Es el recurso que ve la empresa cliente mientras navega el talento y elige a
 * quién quiere conocer. Deliberadamente NO es un `DirectoryCandidateResource`
 * con campos quitados: es una lista blanca. Con lista negra, cualquier campo
 * que alguien agregue mañana al recurso del reclutador aparecería aquí solo, y
 * el día que ese campo sea `contact_phone` nadie se entera hasta que es tarde.
 *
 * Cuatro cosas que faltan a propósito:
 *
 * - `id` y `user_id`. Se expone `public_reference`, opaca y no enumerable. El
 *   motivo está escrito en `routes/api.php`: con el id se podría recorrer la
 *   base probando números.
 * - Nombre, apellido, foto, correo y teléfono. Es el punto entero.
 * - `state` (la etapa del candidato). Saber que alguien está en
 *   `presentado_empresa` le dice a esta empresa que otra ya lo está mirando.
 * - Cualquier archivo. El expediente se abre cuando HUMAE confirma la
 *   entrevista, no antes.
 */
class AnonymousCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->public_reference,
            'display_code' => $this->displayCode(),

            'headline' => $this->headline,
            'position_id' => $this->position_id,
            'functional_area_id' => $this->functional_area_id,
            'career_level_id' => $this->career_level_id,
            'candidate_kind' => $this->candidate_kind?->value,
            'years_of_experience' => $this->years_of_experience,

            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city_id' => $this->city_id,
            'open_to_onsite' => (bool) $this->open_to_onsite,
            'open_to_remote' => (bool) $this->open_to_remote,
            'open_to_hybrid' => (bool) $this->open_to_hybrid,
            'open_to_relocation' => (bool) $this->open_to_relocation,
            'availability' => $this->availability,

            'salary_currency_id' => $this->salary_currency_id,
            'expected_salary_min' => $this->expected_salary_min !== null
                ? (float) $this->expected_salary_min
                : null,
            'expected_salary_max' => $this->expected_salary_max !== null
                ? (float) $this->expected_salary_max
                : null,
            'expected_salary_period' => $this->expected_salary_period,

            // Sólo si rindió psicométricos, nunca el resultado. Que exista una
            // medición es información de compra; qué dice esa medición sobre la
            // persona es del expediente.
            'has_psychometrics' => ((int) ($this->completed_psychometrics_count ?? 0)) > 0,

            'top_skills' => $this->whenLoaded('skills', fn () => $this->skills
                ->take(5)
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'level' => $s->getRelation('pivot')?->getAttribute('level'),
                ])
                ->values()),
            'languages' => $this->whenLoaded('languages', fn () => $this->languages
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'code' => $l->code,
                    'level' => $l->getRelation('pivot')?->getAttribute('level'),
                ])
                ->values()),
        ];
    }

    /**
     * Etiqueta corta y estable para que una persona pueda referirse a un perfil
     * en voz alta: «el A7F3C1». Se deriva de la referencia, así que no agrega
     * información ni hace falta guardarla.
     */
    private function displayCode(): string
    {
        $reference = (string) $this->public_reference;

        return strtoupper(substr(str_replace('-', '', $reference), 0, 6));
    }
}
