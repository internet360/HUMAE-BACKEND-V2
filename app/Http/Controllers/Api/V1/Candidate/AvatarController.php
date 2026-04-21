<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Helpers\LocalFileStorage;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

class AvatarController extends Controller
{
    public function __construct(
        private readonly LocalFileStorage $storage,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $file = $request->file('avatar');
        if ($file === null || ! $file->isValid()) {
            return $this->error('Archivo inválido.', status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY);
        }

        $previousPath = $user->avatar_path;

        try {
            $uploaded = $this->storage->upload($file, 'avatars/'.$user->id, [
                'disk' => 'public',
                'transform' => ['width' => 400, 'height' => 400],
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                'No pudimos subir tu foto. Intenta más tarde.',
                status: HttpStatus::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $user->forceFill([
            'avatar_url' => $uploaded['url'],
            'avatar_path' => $uploaded['public_id'],
        ])->save();

        if ($previousPath !== null && $previousPath !== $uploaded['public_id']) {
            $this->storage->destroy($previousPath, 'public');
        }

        return $this->success(
            message: 'Foto actualizada.',
            data: ['avatar_url' => $uploaded['url']],
        );
    }
}
