<?php

declare(strict_types=1);

namespace App\Support\Sat;

/**
 * Static subsets of the SAT CFDI 4.0 catalogs used by invoice requests.
 * Backend authoritative: the frontend mirrors these codes for its selects.
 *
 * Verified against the official SAT workbook catCFDI_V_4_20261001.xls
 * (sheets c_RegimenFiscal and c_UsoCFDI). Deliberately excluded: régimen 610
 * (foreign residents, no Mexican RFC) and usos CP01/CN01 (payments/payroll
 * complements, never a candidate invoice). The régimen must apply to the RFC
 * person type and the uso must be allowed for the régimen (c_UsoCFDI column
 * "Régimen Fiscal Receptor").
 */
final class SatCatalog
{
    /** @var array<int|string, string> c_RegimenFiscal (numeric codes become int keys) */
    private const REGIMES = [
        '601' => 'General de Ley Personas Morales',
        '603' => 'Personas Morales con Fines no Lucrativos',
        '605' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
        '606' => 'Arrendamiento',
        '607' => 'Régimen de Enajenación o Adquisición de Bienes',
        '608' => 'Demás ingresos',
        '611' => 'Ingresos por Dividendos (socios y accionistas)',
        '612' => 'Personas Físicas con Actividades Empresariales y Profesionales',
        '614' => 'Ingresos por intereses',
        '615' => 'Régimen de los ingresos por obtención de premios',
        '616' => 'Sin obligaciones fiscales',
        '620' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
        '621' => 'Incorporación Fiscal',
        '622' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
        '623' => 'Opcional para Grupos de Sociedades',
        '624' => 'Coordinados',
        '625' => 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
        '626' => 'Régimen Simplificado de Confianza',
    ];

    /** @var array<string, string> c_UsoCFDI */
    private const USES = [
        'G01' => 'Adquisición de mercancías',
        'G02' => 'Devoluciones, descuentos o bonificaciones',
        'G03' => 'Gastos en general',
        'I01' => 'Construcciones',
        'I02' => 'Mobiliario y equipo de oficina por inversiones',
        'I03' => 'Equipo de transporte',
        'I04' => 'Equipo de computo y accesorios',
        'I05' => 'Dados, troqueles, moldes, matrices y herramental',
        'I06' => 'Comunicaciones telefónicas',
        'I07' => 'Comunicaciones satelitales',
        'I08' => 'Otra maquinaria y equipo',
        'D01' => 'Honorarios médicos, dentales y gastos hospitalarios',
        'D02' => 'Gastos médicos por incapacidad o discapacidad',
        'D03' => 'Gastos funerales',
        'D04' => 'Donativos',
        'D05' => 'Intereses reales efectivamente pagados por créditos hipotecarios (casa habitación)',
        'D06' => 'Aportaciones voluntarias al SAR',
        'D07' => 'Primas por seguros de gastos médicos',
        'D08' => 'Gastos de transportación escolar obligatoria',
        'D09' => 'Depósitos en cuentas para el ahorro, primas que tengan como base planes de pensiones',
        'D10' => 'Pagos por servicios educativos (colegiaturas)',
        'S01' => 'Sin efectos fiscales',
    ];

    public const SIN_OBLIGACIONES_FISCALES = '616';

    public const SIN_EFECTOS_FISCALES = 'S01';

    /** Regimes that apply only to personas morales (12-char RFC). */
    private const MORAL_ONLY = ['601', '603', '620', '622', '623', '624'];

    /** Regimes that apply to both person types. */
    private const BOTH = ['626'];

    private const GENERAL_USE_REGIMES = ['601', '603', '606', '612', '620', '621', '622', '623', '624', '625', '626'];

    private const PERSONAL_USE_REGIMES = ['605', '606', '607', '608', '611', '612', '614', '615', '625'];

    /** @return list<string> */
    public static function regimeCodes(): array
    {
        return array_map('strval', array_keys(self::REGIMES));
    }

    /** @return list<string> */
    public static function useCodes(): array
    {
        return array_map('strval', array_keys(self::USES));
    }

    /** @return 'moral'|'fisica' */
    public static function personTypeForRfc(string $rfc): string
    {
        return mb_strlen($rfc) === 12 ? 'moral' : 'fisica';
    }

    /**
     * @param  'moral'|'fisica'  $personType
     * @return list<string>
     */
    public static function regimeCodesFor(string $personType): array
    {
        return array_values(array_filter(
            self::regimeCodes(),
            fn (string $code): bool => in_array($code, self::BOTH, true)
                || in_array($code, self::MORAL_ONLY, true) === ($personType === 'moral'),
        ));
    }

    public static function isUseAllowedForRegime(string $use, string $regime): bool
    {
        return in_array($regime, self::regimesForUse($use), true);
    }

    /** @return list<string> */
    private static function regimesForUse(string $use): array
    {
        return match (true) {
            $use === 'G02' => [...self::GENERAL_USE_REGIMES, self::SIN_OBLIGACIONES_FISCALES],
            $use === 'S01' => self::regimeCodes(),
            str_starts_with($use, 'D') => self::PERSONAL_USE_REGIMES,
            str_starts_with($use, 'G'), str_starts_with($use, 'I') => self::GENERAL_USE_REGIMES,
            default => [],
        };
    }
}
