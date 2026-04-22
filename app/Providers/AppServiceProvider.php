<?php

declare(strict_types=1);

namespace App\Providers;

use App\Helpers\LocalFileStorage;
use App\Helpers\StripeClient;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, static function (): StripeClient {
            return new StripeClient(
                secretKey: (string) config('services.stripe.secret'),
                webhookSecret: (string) config('services.stripe.webhook_secret'),
            );
        });

        $this->app->singleton(LocalFileStorage::class, static function (): LocalFileStorage {
            return new LocalFileStorage;
        });
    }

    public function boot(): void
    {
        $this->configureVerifyEmailUrl();
        $this->configureResetPasswordUrl();
    }

    private function configureVerifyEmailUrl(): void
    {
        VerifyEmail::createUrlUsing(function (MustVerifyEmail $notifiable): string {
            /** @var User $notifiable */
            $apiUrl = URL::temporarySignedRoute(
                'auth.verification.verify',
                Carbon::now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1((string) $notifiable->getEmailForVerification()),
                ]
            );

            $frontend = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');

            return $frontend.'/verify-email?callback='.urlencode($apiUrl);
        });
    }

    private function configureResetPasswordUrl(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontend = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');
            // Los atributos de Eloquent son dinámicos (__get) y property_exists()
            // devuelve false, por lo que antes el email quedaba vacío.
            // getEmailForPasswordReset() es el contrato oficial del trait
            // CanResetPassword y siempre devuelve el email actual del modelo.
            $email = $notifiable instanceof CanResetPassword
                ? $notifiable->getEmailForPasswordReset()
                : '';

            return $frontend.'/reset-password?token='.$token.'&email='.urlencode($email);
        });
    }
}
