<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlacementChargeState;
use App\Enums\SalaryPeriod;
use App\Exceptions\ContractNotSignedException;
use App\Models\CompanyContract;
use App\Models\PlacementCharge;
use App\Models\User;
use App\Models\VacancyAssignment;
use RuntimeException;

/**
 * Devenga los honorarios de HUMAE cuando una colocación se cierra.
 *
 * Devengar, no cobrar. Se registra que la obligación existe y con qué números;
 * la factura CFDI y el cobro pasan fuera del sistema. Meter honorarios de
 * reclutamiento a una pasarela obligaría a resolver retenciones e IVA sobre
 * servicios profesionales, que es un problema fiscal disfrazado de técnico.
 */
class PlacementChargeService
{
    /**
     * Cuántas veces cabe cada período en un año.
     *
     * `hora` y `dia` no están, y su ausencia es la decisión: anualizarlos
     * exige saber la jornada, y ningún dato del sistema la dice. Inventar «×8×5×52»
     * produciría un número plausible que termina en una factura. Mejor rechazar
     * el período y que alguien registre el sueldo como mensual o anual.
     *
     * @var array<string, int>
     */
    private const ANNUAL_FACTOR = [
        'semana' => 52,
        'quincena' => 24,
        'mes' => 12,
        'anio' => 1,
    ];

    /**
     * @return list<string>
     */
    public static function supportedPeriods(): array
    {
        return array_keys(self::ANNUAL_FACTOR);
    }

    /**
     * Crea el cargo de una asignación ya contratada.
     *
     * Se llama desde `HireService` dentro de su transacción: el cargo y el paso
     * a `hired` son el mismo hecho, y una colocación sin cargo es dinero que
     * HUMAE trabajó y no registró.
     */
    public function accrue(VacancyAssignment $assignment, User $actor): PlacementCharge
    {
        $vacancy = $assignment->vacancy;
        if ($vacancy === null) {
            throw new RuntimeException('La asignación no está vinculada a una vacante.');
        }

        if ($assignment->final_salary_amount === null || $assignment->final_salary_period === null) {
            throw new RuntimeException(
                'Falta capturar el sueldo final confirmado antes de contratar: es la base del cargo.',
            );
        }

        // Segundo candado del riesgo de facturar sin instrumento. El primero
        // está en `InterviewService::schedule()`, pero mover etapas y crear
        // entrevistas son operaciones distintas: se puede recorrer
        // presented → interviewing → finalist → hired respetando la máquina de
        // estados sin haber agendado nunca una entrevista, y ahí no se pasó por
        // el primer candado. Este cierra el agujero venga por donde venga.
        //
        // La adenda de la vacante gana sobre el maestro, y la precedencia es
        // entre dos instrumentos firmados —no entre un instrumento y un campo
        // suelto, que es justo lo que este servicio ya no hace. Una adenda sola
        // basta para cobrar: si existe, la empresa firmó al menos ese honorario.
        // Que además falte el maestro es un problema del gate de entrevistas,
        // no de la cobranza de una colocación que ya ocurrió.
        $contract = CompanyContract::addendumFor($vacancy->id)
            ?? CompanyContract::masterFor($vacancy->company_id);

        if ($contract === null) {
            throw ContractNotSignedException::cannotAccrueCharge();
        }

        $existing = PlacementCharge::acrossCompanies()
            ->where('vacancy_assignment_id', $assignment->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        [$source, $kind, $value] = $this->resolveFee($contract);

        $period = (string) $assignment->final_salary_period;
        $amount = (float) $assignment->final_salary_amount;
        $annualBase = $this->annualize($amount, $period);

        return PlacementCharge::create([
            'company_id' => $vacancy->company_id,
            'vacancy_id' => $vacancy->id,
            'vacancy_assignment_id' => $assignment->id,
            'company_contract_id' => $contract->id,
            'state' => PlacementChargeState::PorFacturar->value,
            'fee_source' => $source,
            'fee_kind' => $kind,
            'fee_value' => $value,
            'final_salary_amount' => $amount,
            'final_salary_period' => $period,
            'annual_base' => $annualBase,
            'amount' => $this->computeAmount($kind, $value, $amount, $annualBase),
            'salary_currency_id' => $assignment->final_salary_currency_id,
            'salary_confirmed_by_user_id' => $assignment->final_salary_confirmed_by_user_id,
            'accrued_by_user_id' => $actor->id,
            'accrued_at' => now(),
        ]);
    }

    /**
     * De dónde salen los honorarios.
     *
     * SIEMPRE de un contrato firmado. Nunca de `vacancies.fee_percentage` ni de
     * `fee_amount`: esos campos son staff-only, la empresa no los ve y nadie
     * los firma. Facturar con ellos producía el peor escenario posible — el
     * cliente abre su contrato, lee un número distinto al de la factura, y
     * tiene razón.
     *
     * Cuando HUMAE quiere cobrar distinto en una vacante, el camino es emitir
     * una adenda para esa vacante y que la empresa la firme. Entonces el
     * honorario especial también es un instrumento, y la precedencia de abajo
     * es entre dos documentos firmados.
     *
     * @return array{0: string, 1: string, 2: float}
     */
    private function resolveFee(CompanyContract $contract): array
    {
        $terms = $contract->terms;
        $kind = is_string($terms['fee_kind'] ?? null) ? $terms['fee_kind'] : null;
        $value = is_numeric($terms['fee_value'] ?? null) ? (float) $terms['fee_value'] : null;

        if ($kind === null || $value === null || $value <= 0) {
            throw new RuntimeException(
                'El contrato firmado no define honorarios utilizables. Revisa la configuración del contrato antes de cerrar la colocación.',
            );
        }

        $source = $contract->isAddendum()
            ? PlacementCharge::SOURCE_VACANCY_ADDENDUM
            : PlacementCharge::SOURCE_CONTRACT;

        return [$source, $kind, $value];
    }

    private function annualize(float $amount, string $period): float
    {
        if (! array_key_exists($period, self::ANNUAL_FACTOR)) {
            throw new RuntimeException(
                "No se puede anualizar un sueldo por «{$period}» sin conocer la jornada. Regístralo por quincena, mes o año.",
            );
        }

        return round($amount * self::ANNUAL_FACTOR[$period], 2);
    }

    private function computeAmount(string $kind, float $value, float $salary, float $annualBase): float
    {
        return match ($kind) {
            'percentage_annual_gross' => round($annualBase * ($value / 100), 2),
            // Múltiplo del sueldo MENSUAL, así que se normaliza a mes primero:
            // «dos meses de sueldo» sobre una base anual serían veinticuatro.
            'monthly_salary_multiple' => round(($annualBase / 12) * $value, 2),
            'fixed_amount' => round($value, 2),
            default => throw new RuntimeException("Forma de honorarios no soportada: «{$kind}»."),
        };
    }

    /**
     * Períodos que el endpoint de sueldo final acepta, como valores del enum.
     *
     * @return list<string>
     */
    public static function supportedPeriodValues(): array
    {
        return array_values(array_filter(
            array_map(static fn (SalaryPeriod $p) => $p->value, SalaryPeriod::cases()),
            static fn (string $p) => array_key_exists($p, self::ANNUAL_FACTOR),
        ));
    }
}
