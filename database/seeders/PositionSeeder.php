<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FunctionalArea;
use App\Models\Position;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Catálogo maestro de puestos (`positions`), leído de un JSON editable.
 *
 * El JSON vive en `database/seeders/data/positions.json` y NO se versiona: se
 * sube a mano al servidor y el seeder se corre desde WHM, para poder cambiar
 * el catálogo sin un deploy. Por eso este seeder es tolerante a que el archivo
 * no exista (dev y CI no lo tienen) pero intolerante a que exista y esté mal:
 * un catálogo a medio cargar es peor que ninguno.
 *
 * Formato:
 *
 *   {
 *     "positions": [
 *       { "code": "quality_engineer", "name": "Ingeniero de Calidad", "area": "quality" },
 *       { "code": "frontend_developer", "name": "…", "area": "it_systems", "legacy": true }
 *     ]
 *   }
 *
 * - `area` es el `code` de una fila de `functional_areas`.
 * - `legacy: true` marca puestos que no vienen del documento del cliente; se
 *   conservan porque perfiles y vacantes guardados apuntan a ellos y se ordenan
 *   al final.
 * - El orden del array es el orden de despliegue en los selectores.
 *
 * Correr solo este seeder: `php artisan db:seed --class=PositionSeeder`
 */
class PositionSeeder extends Seeder
{
    private const DATA_FILE = 'seeders/data/positions.json';

    /** Offset de `sort_order` para que los puestos legacy queden al final. */
    private const LEGACY_SORT_OFFSET = 900;

    /**
     * Ruta del catálogo. Method y no constante para que los tests puedan
     * apuntar a un archivo temporal en lugar de pisar el del desarrollador.
     */
    protected function dataPath(): string
    {
        return database_path(self::DATA_FILE);
    }

    public function run(): void
    {
        $path = $this->dataPath();

        if (! is_file($path)) {
            $this->warn(sprintf(
                'PositionSeeder: no se encontró %s — se omite el catálogo de puestos. '
                .'Súbelo al servidor y vuelve a correr `php artisan db:seed --class=PositionSeeder`.',
                self::DATA_FILE,
            ));

            return;
        }

        $rows = $this->readCatalog($path);

        /** @var array<string, int> $areaIds */
        $areaIds = FunctionalArea::query()->pluck('id', 'code')->all();

        // Todas las áreas se resuelven ANTES de escribir, y la escritura va en
        // transacción: un archivo con un área mal tipeada no debe dejar medio
        // catálogo cargado.
        $this->assertAreasExist($rows, $areaIds);

        $clientSort = 0;
        $legacySort = 0;

        DB::transaction(function () use ($rows, $areaIds, &$clientSort, &$legacySort): void {
            foreach ($rows as $row) {
                $sortOrder = $row['legacy']
                    ? self::LEGACY_SORT_OFFSET + (++$legacySort)
                    : ++$clientSort;

                Position::updateOrCreate(
                    ['code' => $row['code']],
                    [
                        'name' => $row['name'],
                        'functional_area_id' => $areaIds[$row['area']],
                        'sort_order' => $sortOrder,
                        'is_active' => true,
                    ],
                );
            }
        });

        $this->warn(sprintf(
            'PositionSeeder: %d puestos cargados (%d del catálogo del cliente, %d legacy).',
            count($rows),
            $clientSort,
            $legacySort,
        ));
    }

    /**
     * Reporta TODAS las áreas desconocidas de una vez. Ir de a una obliga a
     * subir el archivo, correr el seeder y esperar por cada typo.
     *
     * @param  array<int, array{code: string, name: string, area: string, legacy: bool}>  $rows
     * @param  array<string, int>  $areaIds
     */
    private function assertAreasExist(array $rows, array $areaIds): void
    {
        $unknown = [];

        foreach ($rows as $row) {
            if (! isset($areaIds[$row['area']])) {
                $unknown[$row['area']][] = $row['code'];
            }
        }

        if ($unknown === []) {
            return;
        }

        $detail = implode('; ', array_map(
            static fn (string $area, array $codes): string => sprintf('"%s" (%s)', $area, implode(', ', $codes)),
            array_keys($unknown),
            $unknown,
        ));

        throw new RuntimeException(sprintf(
            'PositionSeeder: %s referencia áreas que no existen en functional_areas: %s. '
            .'Corrige el campo "area" o corre JobTaxonomySeeder antes. '
            .'No se escribió ningún puesto.',
            self::DATA_FILE,
            $detail,
        ));
    }

    /**
     * Lee y valida el JSON. Falla fuerte y con el índice de la fila culpable:
     * el archivo se edita a mano, así que un mensaje genérico obliga a alguien
     * a revisar 73 filas para encontrar la letra que sobra.
     *
     * @return array<int, array{code: string, name: string, area: string, legacy: bool}>
     */
    private function readCatalog(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException(sprintf('PositionSeeder: no se pudo leer %s.', $path));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                sprintf('PositionSeeder: %s no es JSON válido — %s', self::DATA_FILE, $e->getMessage()),
                previous: $e,
            );
        }

        if (! is_array($decoded) || ! isset($decoded['positions']) || ! is_array($decoded['positions'])) {
            throw new RuntimeException(sprintf(
                'PositionSeeder: %s debe ser un objeto con la clave "positions" conteniendo un arreglo.',
                self::DATA_FILE,
            ));
        }

        $rows = [];
        $seen = [];

        /** @var mixed $entry */
        foreach (array_values($decoded['positions']) as $i => $entry) {
            $row = $this->parseRow($entry, $i);

            if (isset($seen[$row['code']])) {
                throw new RuntimeException(sprintf(
                    'PositionSeeder: el código "%s" está repetido en %s (filas %d y %d). '
                    .'Los códigos son la llave del catálogo y deben ser únicos.',
                    $row['code'],
                    self::DATA_FILE,
                    $seen[$row['code']],
                    $i,
                ));
            }

            $seen[$row['code']] = $i;
            $rows[] = $row;
        }

        if ($rows === []) {
            throw new RuntimeException(sprintf(
                'PositionSeeder: %s no tiene ningún puesto. Si la intención era vaciar el '
                .'catálogo, desactiva los puestos desde el panel admin en lugar de borrarlos: '
                .'perfiles y vacantes guardados apuntan a ellos.',
                self::DATA_FILE,
            ));
        }

        return $rows;
    }

    /**
     * @return array{code: string, name: string, area: string, legacy: bool}
     */
    private function parseRow(mixed $entry, int $index): array
    {
        if (! is_array($entry)) {
            throw new RuntimeException(sprintf(
                'PositionSeeder: la fila %d de %s no es un objeto.',
                $index,
                self::DATA_FILE,
            ));
        }

        $row = [];

        foreach (['code', 'name', 'area'] as $field) {
            $value = $entry[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException(sprintf(
                    'PositionSeeder: la fila %d de %s no tiene un "%s" válido (debe ser texto no vacío).',
                    $index,
                    self::DATA_FILE,
                    $field,
                ));
            }

            $row[$field] = trim($value);
        }

        return [
            'code' => $row['code'],
            'name' => $row['name'],
            'area' => $row['area'],
            'legacy' => ($entry['legacy'] ?? false) === true,
        ];
    }

    private function warn(string $message): void
    {
        $this->command->warn($message);
    }
}
