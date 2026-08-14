<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CompanyMemberRole;
use App\Models\CompanyContract;
use App\Models\Vacancy;
use App\Notifications\VacancyFeeProposedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Honorarios propios de una vacante: cuándo avisarle a la empresa.
 *
 * Vive aparte del controller porque la decisión tiene condiciones —cambió, hay
 * algo que firmar, todavía no está firmado— y ninguna es de transporte HTTP.
 */
class VacancyFeeService
{
    /**
     * Avisa a la empresa si el guardado dejó honorarios nuevos por firmar.
     *
     * Se llama DESPUÉS de guardar y con el modelo recién actualizado, porque se
     * apoya en `wasChanged()`: guardar el mismo 20% dos veces no vuelve a
     * notificar. Un aviso repetido por una edición que no movió el número
     * entrena a la gente a ignorar los avisos.
     */
    public function notifyProposalIfChanged(Vacancy $vacancy): void
    {
        if (! $vacancy->wasChanged(['fee_percentage', 'fee_amount'])) {
            return;
        }

        // Volver al contrato general no se notifica: no hay nada que firmar y
        // la vacante simplemente vuelve a la regla que la empresa ya aceptó.
        if (! $this->hasOwnFee($vacancy)) {
            return;
        }

        // Con adenda firmada, el número de esta columna ya no factura nada.
        // Avisar sería pedir una firma que no corresponde.
        if (CompanyContract::addendumFor($vacancy->id) !== null) {
            return;
        }

        $recipients = $vacancy->company
            ?->members()
            ->with('user')
            ->whereIn('role', [CompanyMemberRole::Owner->value, CompanyMemberRole::Manager->value])
            ->get()
            ->pluck('user')
            ->filter()
            ->values();

        // Owner y manager, no lectores: son los únicos que pueden firmar, y
        // avisarle a quien no puede actuar es ruido.
        if ($recipients === null || $recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new VacancyFeeProposedNotification($vacancy));
    }

    private function hasOwnFee(Vacancy $vacancy): bool
    {
        return ($vacancy->fee_percentage !== null && (float) $vacancy->fee_percentage > 0)
            || ($vacancy->fee_amount !== null && (float) $vacancy->fee_amount > 0);
    }
}
