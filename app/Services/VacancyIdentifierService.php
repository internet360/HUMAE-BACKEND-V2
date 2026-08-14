<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Vacancy;
use Cocur\Slugify\Slugify;

/**
 * Slug y folio de una vacante.
 *
 * Vivía privado dentro de `CompanyVacancyController` y salió de ahí cuando el
 * flujo del empleador empezó a crear vacantes desde otro punto de entrada. Dos
 * copias de esta lógica es una manera silenciosa de mintar folios repetidos.
 *
 * Las dos consultas usan `acrossCompanies()`: slug y folio son únicos en toda
 * la plataforma, así que una búsqueda con el scope de tenancy puesto miraría
 * sólo las vacantes de la empresa actual y devolvería un valor ya tomado.
 */
class VacancyIdentifierService
{
    public function uniqueSlug(string $title): string
    {
        $slugify = new Slugify;
        $base = $slugify->slugify($title) ?: 'vacante';
        $slug = $base;
        $i = 1;

        while (Vacancy::acrossCompanies()->where('slug', $slug)->exists()) {
            $i++;
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public function nextCode(): string
    {
        $year = (int) now()->format('Y');
        $prefix = "HUM-{$year}-";

        $last = Vacancy::acrossCompanies()->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->value('code');

        $next = 1;
        if ($last !== null) {
            $segment = substr((string) $last, strlen($prefix));
            $next = ((int) $segment) + 1;
        }

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
