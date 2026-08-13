<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Http\Concerns\ResolvesMyCompany;
use App\Models\CompanyContract;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Firma de la adenda de honorarios de una vacante.
 *
 * Pide menos que el contrato maestro, y es a propósito.
 *
 * La adenda es accesoria: no se puede firmar sin un maestro vigente. Cuando la
 * firma la MISMA persona que firmó ese maestro, su identificación oficial y su
 * selfie ya se acreditaron y siguen resguardadas. Volver a pedirlas no agrega
 * evidencia —es la misma persona, contra el mismo expediente— y sí agrega
 * cuatro pasos a un trámite cuyo contenido es un solo número.
 *
 * Lo que no se recorta es el trazo de la firma ni la aceptación del documento:
 * son los dos actos que obligan, y los dos son sobre ESTA adenda.
 *
 * Si firma otra persona, la excepción desaparece por completo: de ella nadie
 * acreditó nada, así que se le piden los tres archivos igual que en el maestro.
 */
class SignVacancyAddendumRequest extends FormRequest
{
    use ResolvesMyCompany;

    /**
     * Igual que en el maestro, la autorización se resuelve acá y no en el
     * controller: un Form Request valida *después* de autorizar, así que dejarlo
     * en el controller le confirmaría la forma del payload —vía 422— a quien no
     * tiene acceso al endpoint.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User || ! $this->mayActAsCompany($user)) {
            return false;
        }

        [$company, $member] = $this->resolveCompany($user);

        // Sin empresa vinculada la respuesta correcta es 404, y la da el
        // controller: negar acá diría 403 y confundiría "no es tuyo" con
        // "no tienes ninguna".
        if ($company === null) {
            return true;
        }

        return $this->canEdit($user, $member);
    }

    /**
     * El contrato que ya acreditó a quien está firmando, o null si a esta
     * persona todavía no la acreditó ninguno.
     *
     * Se compara contra el firmante del maestro, no contra "algún contrato de la
     * empresa": la identidad se acredita por persona, no por razón social.
     */
    public function evidenceSource(): ?CompanyContract
    {
        $user = $this->user();
        $vacancy = $this->route('vacancy');

        if (! $user instanceof User || ! $vacancy instanceof Vacancy) {
            return null;
        }

        $master = CompanyContract::masterFor($vacancy->company_id);

        if ($master === null || $master->signed_by_user_id !== $user->id) {
            return null;
        }

        return $master;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('contracts.uploads.max_kilobytes', 8192);

        /** @var list<string> $imageMimes */
        $imageMimes = config('contracts.uploads.image_mimes', ['jpg', 'jpeg', 'png', 'webp']);
        $mimes = 'mimes:'.implode(',', $imageMimes);

        $accredited = $this->evidenceSource() !== null;
        $ifNew = $accredited ? 'nullable' : 'required';

        return [
            // Trazo del canvas. Siempre PNG: el frontend exporta con
            // `canvas.toBlob('image/png')` para conservar la transparencia.
            'signature' => ['required', 'file', 'mimes:png', 'max:'.$maxKb],

            'identity' => [$ifNew, 'file', $mimes, 'max:'.$maxKb],
            'selfie' => [$ifNew, 'file', $mimes, 'max:'.$maxKb],

            // Se hereda del maestro cuando firma la misma persona: ya declaró
            // con qué cargo representa a la empresa y no cambió de puesto entre
            // dos documentos del mismo expediente.
            'signer_title' => [$ifNew, 'string', 'min:3', 'max:200'],

            // El aviso de privacidad NO se vuelve a pedir: mismo responsable,
            // mismos datos, misma finalidad, y ya se aceptó al firmar el maestro.
            // Lo que sí hay que aceptar es este documento, que trae honorarios
            // distintos a los que la empresa aceptó entonces.
            'accept_terms' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'signature.required' => 'Falta tu firma.',
            'signature.mimes' => 'La firma debe llegar como imagen PNG.',
            'identity.required' => 'Adjunta una identificación oficial: todavía no firmaste ningún contrato con esta cuenta.',
            'selfie.required' => 'Adjunta una selfie para validar tu identidad.',
            'signer_title.required' => 'Indica el puesto con el que firmas la adenda.',
            'accept_terms.accepted' => 'Debes aceptar la adenda para firmarla.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'signature' => 'firma',
            'identity' => 'identificación oficial',
            'selfie' => 'selfie',
            'signer_title' => 'puesto del firmante',
        ];
    }
}
