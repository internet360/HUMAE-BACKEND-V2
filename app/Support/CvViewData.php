<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CandidateProfile;
use Carbon\CarbonInterface;

/**
 * Datos ya resueltos que consumen las plantillas Blade del CV.
 *
 * Las plantillas solo imprimen. Todo lo derivado —nombre completo,
 * iniciales, imágenes en base64— se calcula una sola vez acá para que
 * todas compartan la misma fuente de verdad.
 */
final readonly class CvViewData
{
    /**
     * @param  list<string>  $contactPieces  Datos de contacto sin escapar; la
     *                                       plantilla los escapa al imprimirlos.
     */
    public function __construct(
        public CandidateProfile $profile,
        public string $fullName,
        public string $initials,
        public array $contactPieces,
        public ?string $avatarSrc,
        public ?string $logoSrc,
        public CarbonInterface $generatedAt,
    ) {}
}
