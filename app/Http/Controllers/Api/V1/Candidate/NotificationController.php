<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notifications = $user->notifications()
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->success(
            message: 'Notificaciones.',
            data: $notifications->map(fn (DatabaseNotification $n) => $this->transform($n))->values(),
            meta: [
                'unread_count' => $user->unreadNotifications()->count(),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'last_page' => $notifications->lastPage(),
                ],
            ],
        );
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notification = $user->notifications()->where('id', $id)->first();

        if ($notification === null) {
            return $this->error('Notificación no encontrada.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        $notification->markAsRead();

        return $this->success(
            message: 'Notificación marcada como leída.',
            data: $this->transform($notification->fresh() ?? $notification),
        );
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->unreadNotifications()->update(['read_at' => now()]);

        return $this->success(
            message: 'Todas las notificaciones se marcaron como leídas.',
            data: ['unread_count' => 0],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? class_basename($notification->type),
            'title' => $data['title'] ?? 'Notificación',
            'body' => $data['body'] ?? null,
            'data' => $data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
