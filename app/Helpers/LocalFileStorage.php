<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Guarda archivos en disco del mismo servidor que corre el backend.
 *
 * Reemplaza al antiguo CloudinaryClient conservando la misma firma para
 * que los controllers no dependan del driver concreto.
 */
class LocalFileStorage
{
    /**
     * @param  array{disk?: string, transform?: array{width?: int, height?: int}}  $options
     * @return array{url: string|null, public_id: string, mime_type: string|null, size: int|null}
     */
    public function upload(UploadedFile $file, string $folder, array $options = []): array
    {
        $disk = $options['disk'] ?? 'public';
        $transform = $options['transform'] ?? null;
        $folder = trim($folder, '/');

        if ($transform !== null) {
            [$path, $size, $mime] = $this->storeTransformed($file, $folder, $disk, $transform);
        } else {
            [$path, $size, $mime] = $this->storeRaw($file, $folder, $disk);
        }

        return [
            'url' => $disk === 'public' ? Storage::disk('public')->url($path) : null,
            'public_id' => $path,
            'mime_type' => $mime,
            'size' => $size,
        ];
    }

    public function destroy(string $publicId, string $disk = 'public'): void
    {
        try {
            if (Storage::disk($disk)->exists($publicId)) {
                Storage::disk($disk)->delete($publicId);
            }
        } catch (Throwable) {
            // Nunca bloqueamos el borrado del registro de dominio por
            // un fallo al limpiar el archivo físico.
        }
    }

    /**
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    private function storeRaw(UploadedFile $file, string $folder, string $disk): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = Str::random(40).'.'.$extension;

        Storage::disk($disk)->putFileAs($folder, $file, $filename);

        return [
            $folder.'/'.$filename,
            $file->getSize() ?: null,
            $file->getMimeType(),
        ];
    }

    /**
     * @param  array{width?: int, height?: int}  $transform
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    private function storeTransformed(UploadedFile $file, string $folder, string $disk, array $transform): array
    {
        $manager = new ImageManager(new GdDriver);
        $image = $manager->read($file->getRealPath() ?: '');

        $width = $transform['width'] ?? 400;
        $height = $transform['height'] ?? 400;
        $image->cover($width, $height);

        $encoded = (string) $image->toWebp(85);
        $filename = Str::random(40).'.webp';
        $path = $folder.'/'.$filename;

        Storage::disk($disk)->put($path, $encoded);

        return [$path, strlen($encoded) ?: null, 'image/webp'];
    }
}
