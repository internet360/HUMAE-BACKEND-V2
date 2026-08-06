<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Http\Concerns\ResolvesMyCompany;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Firma del contrato de prestación de servicios por parte de la empresa.
 *
 * Las aceptaciones son obligatorias y explícitas (`accepted`): el registro de
 * que la persona marcó la casilla es parte de la evidencia de la firma, no una
 * formalidad de UI.
 */
class SignCompanyContractRequest extends FormRequest
{
    use ResolvesMyCompany;

    /**
     * La autorización se resuelve acá, no en el controller, porque un Form
     * Request valida *después* de autorizar. Con el chequeo en el controller un
     * candidato que POSTea sin archivos recibía 422 en vez de 403 — le
     * confirmábamos la forma del payload a quien no tiene acceso al endpoint.
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('contracts.uploads.max_kilobytes', 8192);

        /** @var list<string> $imageMimes */
        $imageMimes = config('contracts.uploads.image_mimes', ['jpg', 'jpeg', 'png', 'webp']);
        $mimes = 'mimes:'.implode(',', $imageMimes);

        return [
            // Trazo del canvas. Siempre PNG: el frontend exporta con
            // `canvas.toBlob('image/png')` para conservar la transparencia.
            'signature' => ['required', 'file', 'mimes:png', 'max:'.$maxKb],
            'identity' => ['required', 'file', $mimes, 'max:'.$maxKb],
            'selfie' => ['required', 'file', $mimes, 'max:'.$maxKb],

            // Puesto declarado por quien firma. Va al contrato tal cual.
            'signer_title' => ['required', 'string', 'min:3', 'max:200'],

            'accept_privacy' => ['required', 'accepted'],
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
            'identity.required' => 'Adjunta una identificación oficial.',
            'selfie.required' => 'Adjunta una selfie para validar tu identidad.',
            'signer_title.required' => 'Indica el puesto con el que firmas el contrato.',
            'accept_privacy.accepted' => 'Debes aceptar el aviso de privacidad para firmar.',
            'accept_terms.accepted' => 'Debes aceptar el contrato para firmarlo.',
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
