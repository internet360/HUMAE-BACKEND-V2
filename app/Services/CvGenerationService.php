<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CvTemplate;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Support\CvSampleProfile;
use App\Support\CvViewData;
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
        $data = $this->buildViewData($user);
        $profile = $data->profile;

        $html = View::make($this->resolveTemplate($profile)->view(), ['cv' => $data])->render();

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
     * Renderiza una plantilla como HTML, sin pasar por DomPDF.
     *
     * Es lo que consume la vista previa del selector: el navegador muestra
     * exactamente el mismo Blade que después imprime el PDF.
     */
    /**
     * @return array{html: string, is_sample: bool}
     */
    public function renderHtml(User $user, CvTemplate $template): array
    {
        $real = $this->buildViewData($user);
        $isSample = CvSampleProfile::isNeededFor($real->profile);
        $data = $isSample ? $this->withSampleContent($real) : $real;

        return [
            'html' => View::make($template->view(), ['cv' => $data])->render(),
            'is_sample' => $isSample,
        ];
    }

    public function selectedTemplate(User $user): CvTemplate
    {
        return $this->resolveTemplate($this->profiles->findOrCreate($user));
    }

    public function selectTemplate(User $user, CvTemplate $template): void
    {
        $this->profiles->findOrCreate($user)->update(['cv_template' => $template]);
    }

    /**
     * Reemplaza el cuerpo del CV por contenido de ejemplo, conservando lo que
     * sí es del candidato: su nombre, su foto y su resumen si lo escribió.
     */
    private function withSampleContent(CvViewData $data): CvViewData
    {
        $sample = CvSampleProfile::make();
        $sample->summary = $data->profile->summary ?: $sample->summary;

        return new CvViewData(
            profile: $sample,
            fullName: $data->fullName,
            initials: $data->initials,
            contactPieces: $data->contactPieces !== []
                ? $data->contactPieces
                : ['tu@correo.com', '+52 55 0000 0000'],
            avatarSrc: $data->avatarSrc,
            logoSrc: $data->logoSrc,
            generatedAt: $data->generatedAt,
        );
    }

    /**
     * Resuelve todo lo que las plantillas necesitan imprimir.
     *
     * Es público porque la vista previa del selector de plantillas renderiza
     * el mismo Blade sin pasar por DomPDF.
     */
    public function buildViewData(User $user): CvViewData
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

        $fullName = trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''));
        $displayName = $fullName !== '' ? $fullName : (string) $user->name;

        return new CvViewData(
            profile: $profile,
            fullName: $displayName,
            initials: $this->initials($displayName),
            contactPieces: $this->contactPieces($user, $profile),
            avatarSrc: $this->avatarDataUri($user),
            logoSrc: $this->logoDataUri(),
            generatedAt: now(),
        );
    }

    /**
     * La columna trae siempre un valor válido, pero un perfil recién creado
     * puede no tenerla cargada todavía. Ante la duda, la plantilla por default.
     */
    private function resolveTemplate(CandidateProfile $profile): CvTemplate
    {
        return $profile->cv_template ?? CvTemplate::default();
    }

    /**
     * @return list<string>
     */
    private function contactPieces(User $user, CandidateProfile $profile): array
    {
        $pieces = [
            $profile->contact_email ?? $user->email,
            $profile->contact_phone,
            $profile->linkedin_url,
            $profile->portfolio_url,
        ];

        return array_values(array_filter(
            $pieces,
            static fn (?string $piece): bool => $piece !== null && trim($piece) !== '',
        ));
    }

    private function initials(string $name): string
    {
        $initials = '';

        foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
            if ($part !== '' && mb_strlen($initials) < 2) {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }

        return $initials;
    }

    /**
     * Inlinea el logo como data URI. Además de servir en el navegador para la
     * vista previa, evita que el render dependa del chroot de DomPDF.
     */
    private function logoDataUri(): ?string
    {
        $path = resource_path('views/pdf/humae-logo.png');

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($contents);
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
