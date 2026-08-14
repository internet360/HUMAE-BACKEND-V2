<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Crea el administrador inicial, sin tocarlo si ya existe.
     *
     * `firstOrCreate` y no `updateOrCreate`: con el segundo, cada corrida volvía
     * a escribir la contraseña. En producción eso significaba que cualquier
     * `db:seed --force` posterior —un deploy, un mantenimiento— devolvía la
     * cuenta de administrador a una clave que está en el repositorio, en
     * silencio y sin error. La contraseña de un usuario existente es del
     * usuario, no del seeder.
     *
     * La contraseña de acá abajo sigue siendo pública: está en el repositorio y
     * sirve para levantar un ambiente nuevo, nada más. Ahora sólo se aplica al
     * crear la cuenta. **En producción hay que cambiarla desde la aplicación
     * apenas se entra por primera vez.**
     *
     * Se evaluó leerla de `ADMIN_SEED_PASSWORD`, pero `env()` fuera de `config/`
     * devuelve el default cuando la config está cacheada —que es justamente el
     * caso en producción—, así que habría parecido configurable sin serlo.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@humae.com.mx'],
            [
                'name' => 'HUMAE Admin',
                'password' => Hash::make('humae_admin_2026'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        if (! $admin->hasRole(UserRole::Admin->value)) {
            $admin->assignRole(UserRole::Admin->value);
        }
    }
}
