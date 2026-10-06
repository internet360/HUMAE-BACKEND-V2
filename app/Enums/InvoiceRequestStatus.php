<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceRequestStatus: string
{
    case Requested = 'requested';
    case InProgress = 'in_progress';
    case Issued = 'issued';
    case CancellationPending = 'cancellation_pending';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Solicitada',
            self::InProgress => 'En proceso',
            self::Issued => 'Emitida',
            self::CancellationPending => 'Cancelación pendiente',
            self::Rejected => 'Rechazada',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * `issued` is reachable from `requested` only because the files endpoint
     * (PDF + XML + UUID) is the single way to get there; the plain status
     * endpoint must not offer it.
     */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Requested => in_array($to, [self::InProgress, self::Rejected, self::Issued], true),
            self::InProgress => in_array($to, [self::Issued, self::Rejected], true),
            self::Issued => $to === self::CancellationPending,
            self::CancellationPending => in_array($to, [self::Cancelled, self::Issued], true),
            self::Rejected, self::Cancelled => false,
        };
    }

    /** A rejected or cancelled request no longer holds its payments. */
    public function releasesClaim(): bool
    {
        return $this === self::Rejected || $this === self::Cancelled;
    }
}
