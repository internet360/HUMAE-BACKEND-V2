<?php

declare(strict_types=1);

use App\Enums\InvoiceRequestStatus as S;

it('has Spanish labels', function (): void {
    expect(collect(S::cases())->mapWithKeys(fn (S $s) => [$s->value => $s->label()])->all())->toBe([
        'requested' => 'Solicitada',
        'in_progress' => 'En proceso',
        'issued' => 'Emitida',
        'cancellation_pending' => 'Cancelación pendiente',
        'rejected' => 'Rechazada',
        'cancelled' => 'Cancelada',
    ]);
});

it('allows only the designed transitions', function (S $from, array $allowed): void {
    foreach (S::cases() as $to) {
        expect($from->canTransitionTo($to))->toBe(in_array($to, $allowed, true), "{$from->value} -> {$to->value}");
    }
})->with([
    [S::Requested, [S::InProgress, S::Rejected, S::Issued]],
    [S::InProgress, [S::Issued, S::Rejected]],
    [S::Issued, [S::CancellationPending]],
    [S::CancellationPending, [S::Cancelled, S::Issued]],
    [S::Rejected, []],
    [S::Cancelled, []],
]);

it('releases the payment claim only when rejected or cancelled', function (): void {
    expect(collect(S::cases())->filter->releasesClaim()->values()->all())->toBe([S::Rejected, S::Cancelled]);
});
