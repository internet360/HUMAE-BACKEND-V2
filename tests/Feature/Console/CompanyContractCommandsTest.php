<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CompanyContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('services.cincel.jwt', 'test-jwt');
    config()->set('services.cincel.retry_delay_ms', 0);
    Http::preventStrayRequests();
});

/**
 * Contrato con sus archivos realmente presentes en el disco, para poder
 * distinguir «anulado» de «purgado».
 */
function contractWithFiles(Company $company, bool $timestamped = true): CompanyContract
{
    $contract = CompanyContract::factory()
        ->when(! $timestamped, fn ($f) => $f->withoutTimestamp())
        ->create(['company_id' => $company->id]);

    foreach ($contract->storedPaths() as $path) {
        Storage::disk('local')->put($path, 'contenido');
    }

    return $contract;
}

describe('contracts:void', function (): void {
    it('anula sin destruir la evidencia y habilita firmar de nuevo', function (): void {
        $company = Company::factory()->create();
        $contract = contractWithFiles($company);
        $paths = $contract->storedPaths();

        $this->artisan('contracts:void', ['company' => $company->id, '--force' => true])
            ->assertSuccessful();

        // La fila sigue existiendo, sólo marcada como anulada.
        //
        // Se comprueba con `deleted_at` y no con `fresh()`: `fresh()` consulta
        // con `newQueryWithoutScopes()`, así que devuelve el modelo igual aunque
        // esté anulado.
        expect(CompanyContract::acrossCompanies()->withTrashed()->count())->toBe(1)
            ->and(CompanyContract::acrossCompanies()->count())->toBe(0)
            ->and($contract->fresh()?->deleted_at)->not->toBeNull();

        // Y el PDF, la huella y la constancia siguen resguardados.
        foreach ($paths as $path) {
            expect(Storage::disk('local')->exists($path))->toBeTrue();
        }

        // Lo que importa para el flujo: la empresa vuelve a poder firmar.
        expect($company->fresh()?->latestContract)->toBeNull();
    });

    it('purga fila y archivos sólo cuando se pide explícitamente', function (): void {
        $company = Company::factory()->create();
        $contract = contractWithFiles($company);
        $paths = $contract->storedPaths();

        expect($paths)->not->toBeEmpty();

        $this->artisan('contracts:void', [
            'company' => $company->id,
            '--purge' => true,
            '--force' => true,
        ])->assertSuccessful();

        expect(CompanyContract::acrossCompanies()->withTrashed()->count())->toBe(0);

        foreach ($paths as $path) {
            expect(Storage::disk('local')->exists($path))->toBeFalse();
        }
    });

    it('acepta el slug de la empresa además del id', function (): void {
        $company = Company::factory()->create(['slug' => 'acme-corp-test']);
        contractWithFiles($company);

        $this->artisan('contracts:void', ['company' => 'acme-corp-test', '--force' => true])
            ->assertSuccessful();

        expect(CompanyContract::acrossCompanies()->count())->toBe(0);
    });

    it('no toca nada si se cancela la confirmación', function (): void {
        $company = Company::factory()->create();
        contractWithFiles($company);

        $this->artisan('contracts:void', ['company' => $company->id])
            ->expectsConfirmation('¿Anular?', 'no')
            ->assertSuccessful();

        expect(CompanyContract::acrossCompanies()->count())->toBe(1);
    });

    it('falla con una empresa inexistente', function (): void {
        $this->artisan('contracts:void', ['company' => '999999', '--force' => true])
            ->assertFailed();
    });

    it('no revienta cuando la empresa no tiene contrato', function (): void {
        $company = Company::factory()->create();

        $this->artisan('contracts:void', ['company' => $company->id, '--force' => true])
            ->assertSuccessful();
    });
});

describe('contracts:retry-timestamps', function (): void {
    it('sella los contratos que quedaron sin constancia', function (): void {
        Http::fake(['api.cincel.digital/*' => Http::response('asn1-recuperado', 200)]);

        $company = Company::factory()->create();
        $contract = contractWithFiles($company, timestamped: false);

        expect($contract->timestamp_path)->toBeNull();

        $this->artisan('contracts:retry-timestamps')->assertSuccessful();

        $contract->refresh();

        expect($contract->timestamp_path)->not->toBeNull()
            ->and($contract->timestamped_at)->not->toBeNull()
            ->and(Storage::disk('local')->exists((string) $contract->timestamp_path))->toBeTrue();
    });

    it('no reintenta los que ya tienen constancia', function (): void {
        $company = Company::factory()->create();
        contractWithFiles($company, timestamped: true);

        // `preventStrayRequests` hace fallar el test si llama a CINCEL.
        $this->artisan('contracts:retry-timestamps')
            ->expectsOutputToContain('Todos los contratos tienen su constancia')
            ->assertSuccessful();
    });

    it('sale con éxito cuando CINCEL sigue caído, para no inundar el cron de correos', function (): void {
        Http::fake(['api.cincel.digital/*' => Http::response('down', 503)]);

        $company = Company::factory()->create();
        $contract = contractWithFiles($company, timestamped: false);

        $this->artisan('contracts:retry-timestamps')->assertSuccessful();

        expect($contract->fresh()?->timestamp_path)->toBeNull();
    });

    it('--dry-run lista los pendientes sin llamar a CINCEL', function (): void {
        $company = Company::factory()->create();
        $contract = contractWithFiles($company, timestamped: false);

        // Sin fake de Http: si el comando llamara a CINCEL, `preventStrayRequests`
        // haría fallar el test.
        $this->artisan('contracts:retry-timestamps', ['--dry-run' => true])
            ->expectsOutputToContain($contract->folio)
            ->assertSuccessful();

        expect($contract->fresh()?->timestamp_path)->toBeNull();
    });

    it('respeta el límite de la corrida', function (): void {
        Http::fake(['api.cincel.digital/*' => Http::response('asn1', 200)]);

        $company = Company::factory()->create();
        CompanyContract::factory()->withoutTimestamp()->count(3)
            ->create(['company_id' => $company->id]);

        $this->artisan('contracts:retry-timestamps', ['--limit' => 1])->assertSuccessful();

        expect(
            CompanyContract::acrossCompanies()->whereNull('timestamp_path')->count(),
        )->toBe(2);
    });

    it('ignora los contratos anulados', function (): void {
        $company = Company::factory()->create();
        $contract = contractWithFiles($company, timestamped: false);
        $contract->delete();

        $this->artisan('contracts:retry-timestamps')
            ->expectsOutputToContain('Todos los contratos tienen su constancia')
            ->assertSuccessful();
    });
});
