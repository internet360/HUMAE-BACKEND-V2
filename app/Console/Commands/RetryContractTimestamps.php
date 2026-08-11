<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CompanyContract;
use App\Services\CompanyContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sella los contratos que quedaron sin constancia NOM-151.
 *
 * `CompanyContractService::sign()` no aborta la firma si CINCEL no responde: el
 * contrato queda emitido con `timestamp_path` en NULL, porque perder la firma de
 * una empresa por una caída de un tercero sería el peor comportamiento posible.
 * Ese diseño necesita este comando: sin él los contratos sin sellar se acumulan
 * y nadie se entera.
 *
 * Corre en el scheduler cada hora, así un contrato firmado durante una caída
 * —o antes de tener credencial de CINCEL— se sella solo cuando se puede.
 */
class RetryContractTimestamps extends Command
{
    protected $signature = 'contracts:retry-timestamps
        {--limit=25 : Cuántos contratos intentar en esta corrida}
        {--dry-run : Solo listar los pendientes, sin llamar a CINCEL}';

    protected $description = 'Reintenta la constancia NOM-151 de los contratos que quedaron sin sellar';

    public function handle(CompanyContractService $contracts): int
    {
        $limit = max(1, (int) $this->option('limit'));

        /** @var list<CompanyContract> $pending */
        $pending = CompanyContract::acrossCompanies()
            ->whereNull('timestamp_path')
            // `withTrashed` en la empresa: `Company` usa soft deletes, así que
            // sin esto una empresa anulada dejaría la relación en null y el
            // listado del --dry-run reventaría. El contrato hay que sellarlo
            // igual: la obligación de conservar la evidencia no depende de que
            // la cuenta siga activa.
            ->with(['company' => fn ($query) => $query->withTrashed()])
            ->orderBy('signed_at')
            ->limit($limit)
            ->get()
            ->all();

        if ($pending === []) {
            $this->info('Todos los contratos tienen su constancia.');

            return self::SUCCESS;
        }

        $total = CompanyContract::acrossCompanies()->whereNull('timestamp_path')->count();
        $this->line(sprintf('Pendientes: %d (se intentan %d)', $total, count($pending)));

        if ($this->option('dry-run')) {
            $this->table(
                ['Folio', 'Firmado', 'Empresa'],
                array_map(fn (CompanyContract $c): array => [
                    $c->folio,
                    $c->signed_at->format('Y-m-d H:i'),
                    $c->company->legal_name ?? '—',
                ], $pending),
            );

            return self::SUCCESS;
        }

        $sealed = 0;
        $failed = 0;

        foreach ($pending as $contract) {
            try {
                if ($contracts->retryTimestamp($contract)) {
                    $sealed++;
                    $this->line("  sellado    {$contract->folio}");

                    continue;
                }

                // CINCEL sigue sin entregar: el servicio ya lo reportó. Se corta
                // la corrida — si el proveedor está caído, insistir con los demás
                // solo suma latencia y ruido en los logs.
                $failed++;
                $this->warn("  pendiente  {$contract->folio} (CINCEL no entregó la constancia)");
                break;
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->error("  error      {$contract->folio}: {$e->getMessage()}");
                break;
            }
        }

        $this->info(sprintf('Sellados: %d · sin sellar en esta corrida: %d', $sealed, $failed));

        // Éxito aunque queden pendientes: que CINCEL esté caído no es una falla
        // del comando, y un exit code distinto de cero llenaría de correos al
        // cron de cPanel cada hora.
        return self::SUCCESS;
    }
}
