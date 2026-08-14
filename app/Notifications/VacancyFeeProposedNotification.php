<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Vacancy;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa a la empresa que HUMAE propuso honorarios propios para una vacante.
 *
 * Existe porque el estado «propuesto y sin firmar» era invisible: sólo se
 * descubría entrando al detalle de esa vacante y desplazándose hasta una
 * tarjeta. Una empresa podía cerrar una contratación creyendo que aplicaba su
 * contrato general, sin saber que había un número esperando su firma.
 *
 * El aviso dice el número. Ocultarlo para forzar el clic convertiría una
 * notificación informativa en un anzuelo, y este es el dato que decide si la
 * persona quiere firmar o llamar a discutirlo.
 */
class VacancyFeeProposedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Vacancy $vacancy,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->vacancy->title;

        return (new MailMessage)
            ->subject("Honorarios propuestos para {$title}")
            ->greeting('Hola')
            ->line("Propusimos honorarios propios para tu vacante {$title}: {$this->feeSentence()}.")
            ->line('Es una propuesta, no un cargo. Mientras no firmes la adenda, esta vacante se factura con los honorarios de tu contrato general.')
            ->action('Revisar y firmar', $this->url())
            ->line('Si el número no te cuadra, escríbenos antes de firmar.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'vacancy.fee_proposed',
            'vacancy_id' => $this->vacancy->id,
            'vacancy_title' => $this->vacancy->title,
            'vacancy_code' => $this->vacancy->code,
            'fee' => $this->feeSentence(),
            'url' => $this->url(),
            'title' => 'Honorarios pendientes de firma',
            'body' => "Propusimos {$this->feeSentence()} para {$this->vacancy->title}. Falta tu firma.",
        ];
    }

    /**
     * El honorario en palabras, con la misma precedencia que aplica
     * `CompanyContractService::addendumTerms`: si estuvieran los dos, se firma
     * el porcentaje.
     */
    private function feeSentence(): string
    {
        if ($this->vacancy->fee_percentage !== null && (float) $this->vacancy->fee_percentage > 0) {
            return rtrim(rtrim(number_format((float) $this->vacancy->fee_percentage, 2), '0'), '.').'% del sueldo anual';
        }

        if ($this->vacancy->fee_amount !== null && (float) $this->vacancy->fee_amount > 0) {
            return '$'.number_format((float) $this->vacancy->fee_amount, 2).' fijos';
        }

        return 'honorarios por definir';
    }

    private function url(): string
    {
        $base = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');

        return $base.'/me/empresa/vacantes/'.$this->vacancy->id;
    }
}
