<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Enums\CvTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidate\UpdateCvTemplateRequest;
use App\Models\User;
use App\Services\CvGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CvController extends Controller
{
    public function __construct(
        private readonly CvGenerationService $service,
    ) {}

    public function download(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->service->generate($user);

        return response($result['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Catálogo de plantillas más la que el candidato tiene elegida.
     *
     * El catálogo se sirve desde acá —y no se replica en el frontend— para que
     * sumar una plantilla sea un cambio de un solo repositorio.
     */
    public function templates(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->success('OK', [
            'selected' => $this->service->selectedTemplate($user)->value,
            'templates' => array_map(
                static fn (CvTemplate $template): array => [
                    'key' => $template->value,
                    'name' => $template->label(),
                    'description' => $template->description(),
                ],
                CvTemplate::cases(),
            ),
        ]);
    }

    /**
     * Devuelve la plantilla renderizada como HTML para la vista previa.
     *
     * El frontend lo inyecta en un iframe con sandbox y sin scripts; por eso
     * viaja dentro del envelope y no como text/html navegable.
     *
     * is_sample avisa que el perfil está vacío y lo que se ve es contenido de
     * ejemplo, para que la UI pueda decirlo y nadie crea que ese es su CV.
     */
    public function preview(Request $request, CvTemplate $template): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $preview = $this->service->renderHtml($user, $template);

        return $this->success('OK', [
            'template' => $template->value,
            'html' => $preview['html'],
            'is_sample' => $preview['is_sample'],
        ]);
    }

    public function updateTemplate(UpdateCvTemplateRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $template = CvTemplate::from((string) $request->validated('template'));
        $this->service->selectTemplate($user, $template);

        return $this->success('Plantilla actualizada.', ['selected' => $template->value]);
    }
}
