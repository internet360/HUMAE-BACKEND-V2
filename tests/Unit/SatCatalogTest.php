<?php

declare(strict_types=1);

use App\Support\Sat\SatCatalog;

// Source of truth: SAT workbook catCFDI_V_4_20261001.xls (sheets c_RegimenFiscal / c_UsoCFDI),
// minus 610 (foreign residents) and CP01/CN01, which a candidate invoice never uses.

it('exposes exactly the supported regimes', function (): void {
    expect(SatCatalog::regimeCodes())->toBe([
        '601', '603', '605', '606', '607', '608', '611', '612', '614', '615',
        '616', '620', '621', '622', '623', '624', '625', '626',
    ])->and(SatCatalog::regimeCodes())->not->toContain('610');
});

it('exposes exactly the supported CFDI uses', function (): void {
    expect(SatCatalog::useCodes())->toBe([
        'G01', 'G02', 'G03', 'I01', 'I02', 'I03', 'I04', 'I05', 'I06', 'I07', 'I08',
        'D01', 'D02', 'D03', 'D04', 'D05', 'D06', 'D07', 'D08', 'D09', 'D10', 'S01',
    ])->and(SatCatalog::useCodes())->not->toContain('CP01')->not->toContain('CN01');
});

it('splits regimes by person type', function (): void {
    expect(SatCatalog::regimeCodesFor('moral'))->toBe(['601', '603', '620', '622', '623', '624', '626'])
        ->and(SatCatalog::regimeCodesFor('fisica'))->toBe([
            '605', '606', '607', '608', '611', '612', '614', '615', '616', '621', '625', '626',
        ]);
});

it('derives the person type from the RFC length', function (): void {
    expect(SatCatalog::personTypeForRfc('ABC010101AAA'))->toBe('moral')
        ->and(SatCatalog::personTypeForRfc('ABCD010101AAA'))->toBe('fisica');
});

it('matches the official c_UsoCFDI x c_RegimenFiscal matrix', function (): void {
    $general = ['601', '603', '606', '612', '620', '621', '622', '623', '624', '625', '626'];
    $personal = ['605', '606', '607', '608', '611', '612', '614', '615', '625'];

    $expected = [
        'G01' => $general,
        'G02' => ['601', '603', '606', '612', '616', '620', '621', '622', '623', '624', '625', '626'],
        'G03' => $general,
        'I01' => $general, 'I02' => $general, 'I03' => $general, 'I04' => $general,
        'I05' => $general, 'I06' => $general, 'I07' => $general, 'I08' => $general,
        'D01' => $personal, 'D02' => $personal, 'D03' => $personal, 'D04' => $personal, 'D05' => $personal,
        'D06' => $personal, 'D07' => $personal, 'D08' => $personal, 'D09' => $personal, 'D10' => $personal,
        'S01' => [
            '601', '603', '605', '606', '607', '608', '611', '612', '614', '615',
            '616', '620', '621', '622', '623', '624', '625', '626',
        ],
    ];

    $actual = [];
    foreach (SatCatalog::useCodes() as $use) {
        $actual[$use] = array_values(array_filter(
            SatCatalog::regimeCodes(),
            fn (string $regime): bool => SatCatalog::isUseAllowedForRegime($use, $regime),
        ));
    }

    expect($actual)->toBe($expected);
});
