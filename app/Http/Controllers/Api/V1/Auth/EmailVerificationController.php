<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::find($id);

        if ($user === null) {
            return $this->error(message: 'Usuario no encontrado.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        if (! hash_equals(sha1((string) $user->getEmailForVerification()), $hash)) {
            return $this->error(message: 'Hash de verificación inválido.', status: HttpStatus::HTTP_FORBIDDEN);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->success(message: 'El email ya estaba verificado.');
        }

        $user->markEmailAsVerified();
        Event::dispatch(new Verified($user));

        return $this->success(message: 'Email verificado correctamente.');
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $this->error(message: 'No autenticado.', status: HttpStatus::HTTP_UNAUTHORIZED);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->success(message: 'El email ya estaba verificado.');
        }

        $user->sendEmailVerificationNotification();

        return $this->success(message: 'Email de verificación reenviado.');
    }

    /**
     * Reenvío público (sin auth) desde /verify-email.
     *
     * Respuesta genérica para los tres casos (usuario existe + pendiente,
     * usuario existe + verificado, usuario no existe) para no filtrar
     * información sobre cuentas. El rate limit está en la ruta.
     */
    public function resendPublic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user !== null && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return $this->success(
            message: 'Si existe una cuenta pendiente con ese correo, te enviamos un nuevo enlace de verificación.',
        );
    }
}
