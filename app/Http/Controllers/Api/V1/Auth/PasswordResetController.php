<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class PasswordResetController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return $this->success(message: 'Si el email existe, recibirás un enlace para restablecer tu contraseña.');
        }

        return $this->success(
            message: 'Si el email existe, recibirás un enlace para restablecer tu contraseña.',
        );
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                Event::dispatch(new PasswordReset($user));
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->success(message: 'Contraseña restablecida correctamente.');
        }

        return $this->error(
            message: 'No fue posible restablecer la contraseña.',
            errors: ['email' => [__($status)]],
            status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
