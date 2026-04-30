<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CandidateProfile;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Throwable;

class CvGenerationService
{
    public function __construct(
        private readonly ProfileService $profiles,
    ) {}

    /**
     * Genera el PDF del CV para el usuario dado. Devuelve los bytes crudos.
     *
     * @return array{filename: string, pdf: string}
     */
    public function generate(User $user): array
    {
        $profile = $this->profiles->findOrCreate($user);

        $profile->load([
            'experiences' => fn ($q) => $q->orderBy('sort_order')->orderByDesc('start_date'),
            'educations' => fn ($q) => $q->orderBy('sort_order')->orderByDesc('end_date'),
            'courses' => fn ($q) => $q->orderBy('sort_order')->orderByDesc('completed_at'),
            'certifications' => fn ($q) => $q->orderBy('sort_order')->orderByDesc('issued_at'),
            'skills',
            'languages',
        ]);

        $html = View::make('pdf.cv', [
            'user' => $user,
            'profile' => $profile,
            'logoPath' => resource_path('views/pdf/humae-logo.png'),
            'avatarSrc' => $this->avatarDataUri($user),
            'generatedAt' => now(),
        ])->render();

        $options = new Options;
        $options->setChroot([resource_path(), base_path('public')]);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $pdf = (string) $dompdf->output();

        $slug = $this->filenameSlug($profile);
        $filename = 'cv-humae-'.$slug.'.pdf';

        return ['filename' => $filename, 'pdf' => $pdf];
    }

    /**
     * Inlinea la foto del candidato como data URI para que DomPDF la
     * renderice sin depender de URLs públicas ni del chroot.
     */
    private function avatarDataUri(User $user): ?string
    {
        $path = $user->avatar_path;
        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($path)) {
                return null;
            }

            $contents = $disk->get($path);
            if (! is_string($contents) || $contents === '') {
                return null;
            }

            $mime = $disk->mimeType($path) ?: 'image/webp';

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (Throwable) {
            return null;
        }
    }

    private function filenameSlug(CandidateProfile $profile): string
    {
        $raw = trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''));
        if ($raw === '') {
            return 'candidato';
        }

        // Mapeo manual de caracteres acentuados a ASCII, más estable que iconv.
        $table = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ];

        $normalized = strtr($raw, $table);
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $normalized));
        $normalized = trim($normalized, '-');

        return $normalized !== '' ? $normalized : 'candidato';
    }
}
