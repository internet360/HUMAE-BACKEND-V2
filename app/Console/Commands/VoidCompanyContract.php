<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Anula el contrato vigente de una empresa para que pueda volver a firmar.
 *
 * Existe porque `POST /me/company/contract` responde 409 si ya hay contrato: sin
 * esto, una empresa que firmó con datos equivocados quedaba sin salida, y probar
 * el flujo dos veces exigía tocar la base a mano.
 *
 * Por defecto **no destruye nada**: marca el contrato como anulado y conserva el
 * PDF, la huella y la constancia, que son la prueba de lo que la empresa aceptó.
 * `--purge` sí borra fila y archivos, y es para entornos de prueba.
 */
class VoidCompanyContract extends Command
{
    protected $signature = 'contracts:void
        {company : ID o slug de la empresa}
        {--purge : Borra la fila y los archivos en lugar de solo anular (destruye evidencia)}
        {--force : No pedir confirmación}';

    protected $description = 'Anula el contrato vigente de una empresa para que pueda firmar de nuevo';

    public function handle(): int
    {
        $needle = (string) $this->argument('company');

        // Se distingue por forma y no con un `orWhere`: comparar un slug contra
        // una columna entera queda a merced del casteo del motor.
        $company = ctype_digit($needle)
            ? Company::acrossCompanies()->find((int) $needle)
            : Company::acrossCompanies()->where('slug', $needle)->first();

        if ($company === null) {
            $this->error("No encontré una empresa con ID o slug «{$needle}».");

            return self::FAILURE;
        }

        /** @var list<CompanyContract> $contracts */
        $contracts = CompanyContract::acrossCompanies()
            ->where('company_id', $company->id)
            ->orderByDesc('signed_at')
            ->get()
            ->all();

        if ($contracts === []) {
            $this->info("«{$company->legal_name}» no tiene contratos vigentes. Nada que hacer.");

            return self::SUCCESS;
        }

        $purge = (bool) $this->option('purge');

        $this->line("Empresa: {$company->legal_name}");
        $this->table(
            ['Folio', 'Firmado', 'Firmante', 'Constancia'],
            array_map(fn (CompanyContract $c): array => [
                $c->folio,
                $c->signed_at->format('Y-m-d H:i'),
                $c->signedBy->email ?? '—',
                $c->isTimestamped() ? 'sí' : 'no',
            ], $contracts),
        );

        if ($purge) {
            $this->warn('--purge borra la fila y los archivos. La evidencia de la firma se pierde.');
        }

        if (! $this->option('force') && ! $this->confirm($purge ? '¿Purgar?' : '¿Anular?', false)) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        $disk = Storage::disk('local');

        foreach ($contracts as $contract) {
            if ($purge) {
                foreach ($contract->storedPaths() as $path) {
                    if ($disk->exists($path)) {
                        $disk->delete($path);
                    }
                }
                $contract->forceDelete();
                $this->line("  purgado  {$contract->folio}");

                continue;
            }

            $contract->delete();
            $this->line("  anulado  {$contract->folio}");
        }

        $this->info(sprintf(
            '%d contrato(s) %s. «%s» puede firmar de nuevo.',
            count($contracts),
            $purge ? 'purgado(s)' : 'anulado(s)',
            $company->legal_name,
        ));

        return self::SUCCESS;
    }
}
