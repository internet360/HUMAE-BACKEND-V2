<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Helpers\LocalFileStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateContractSettingsRequest;
use App\Http\Resources\V1\Admin\ContractSettingResource;
use App\Models\ContractSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

/**
 * Términos comerciales del contrato, editables por un admin.
 *
 * Antes vivían en `config/contracts.php` y cambiar el porcentaje de honorarios
 * exigía editar el `.env` del servidor, correr `config:cache` y reiniciar —solo
 * posible con acceso SSH.
 *
 * Lo que se guarda acá aplica **solo a contratos nuevos**: los ya firmados
 * conservan su copia en `company_contracts.terms` y son inmutables por diseño.
 */
class ContractSettingController extends Controller
{
    public function __construct(
        private readonly LocalFileStorage $storage,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $settings = ContractSetting::current();
        $settings->load('updatedBy');

        return $this->success(
            message: 'Configuración del contrato.',
            data: ContractSettingResource::make($settings),
        );
    }

    public function update(UpdateContractSettingsRequest $request): JsonResponse
    {
        $settings = ContractSetting::current();

        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // El importe en letra solo tiene sentido con monto fijo: si se cambió de
        // forma de cobro, arrastrarlo dejaría un texto que ya no aplica.
        if ($data['fee_kind'] !== 'fixed_amount') {
            $data['fee_amount_words'] = null;
        }

        $settings->fill($data);

        // La versión sube solo cuando algo cambió de verdad, así el número
        // significa "condiciones distintas" y no "alguien abrió el formulario".
        if ($settings->isDirty()) {
            $settings->version = $settings->version + 1;
            $settings->updated_by_user_id = $user->id;
        }

        $settings->save();
        $settings->load('updatedBy');

        return $this->success(
            message: 'Condiciones actualizadas. Aplican a los contratos que se firmen desde ahora.',
            data: ContractSettingResource::make($settings->fresh() ?? $settings),
        );
    }

    /**
     * Carga la firma escaneada del apoderado.
     *
     * Va al disco privado, no al público: es la firma de una persona y no tiene
     * por qué ser accesible por URL. El PDF la incrusta como data URI.
     */
    public function uploadSignature(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $request->validate([
            // PNG obligatorio: el contrato es blanco y solo el PNG conserva la
            // transparencia. Un JPG dibuja un recuadro alrededor de la firma.
            'signature' => ['required', 'file', 'mimes:png', 'max:4096'],
        ], [
            'signature.mimes' => 'La firma debe ser un PNG con fondo transparente.',
        ]);

        $file = $request->file('signature');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return $this->error('Archivo inválido.', status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY);
        }

        $settings = ContractSetting::current();
        $previous = $settings->signature_path;

        try {
            $uploaded = $this->storage->upload($file, 'contracts/settings/signature', ['disk' => 'local']);
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                'No pudimos guardar la firma. Intenta más tarde.',
                status: HttpStatus::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        /** @var User $user */
        $user = $request->user();

        $settings->forceFill([
            'signature_path' => $uploaded['public_id'],
            'updated_by_user_id' => $user->id,
        ])->save();

        /*
         * La firma anterior se borra sólo después de guardar la nueva.
         *
         * No afecta a los contratos ya emitidos: cada uno guarda la ruta de la
         * firma en su snapshot, y su PDF ya está almacenado con la imagen
         * incrustada.
         */
        if (is_string($previous) && $previous !== '' && $previous !== $uploaded['public_id']) {
            $this->storage->destroy($previous, 'local');
        }

        return $this->success(
            message: 'Firma cargada.',
            data: ContractSettingResource::make($settings->fresh() ?? $settings),
        );
    }

    public function destroySignature(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $settings = ContractSetting::current();
        $path = $settings->signature_path;

        if (is_string($path) && $path !== '') {
            $this->storage->destroy($path, 'local');
        }

        $settings->forceFill(['signature_path' => null])->save();

        return $this->success(
            message: 'Firma eliminada. Los contratos nuevos saldrán sin la firma de HUMAE.',
            data: ContractSettingResource::make($settings->fresh() ?? $settings),
        );
    }

    /** Vista previa de la firma cargada, para mostrarla en el panel. */
    public function showSignature(Request $request): Response|JsonResponse
    {
        $this->ensureAdmin($request);

        $path = ContractSetting::current()->signature_path;

        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            return $this->error('No hay firma cargada.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        return response(Storage::disk('local')->get($path), HttpStatus::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function ensureAdmin(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasRole(UserRole::Admin->value)) {
            abort(HttpStatus::HTTP_FORBIDDEN, 'Solo admin.');
        }
    }
}
