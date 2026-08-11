<?php

declare(strict_types=1);

use App\Models\FunctionalArea;
use App\Models\Position;
use Database\Seeders\PositionSeeder;
use Illuminate\Console\Command;

/**
 * El catálogo de puestos se lee de un JSON que NO está versionado: se edita a
 * mano y se sube al servidor. Estos tests cubren justamente eso — que un
 * archivo mal editado falle fuerte y claro, y que su ausencia no rompa
 * `migrate:fresh --seed` en local ni en CI.
 */
beforeEach(function (): void {
    $this->dataFile = tempnam(sys_get_temp_dir(), 'positions-').'.json';

    $this->seedWith = function (?array $payload) {
        if ($payload === null) {
            @unlink($this->dataFile);
        } else {
            file_put_contents($this->dataFile, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        return ($this->seeder)()->run();
    };

    $this->seedRaw = function (string $raw) {
        file_put_contents($this->dataFile, $raw);

        return ($this->seeder)()->run();
    };

    // Vía Artisan el seeder siempre recibe un Command; instanciándolo a mano no.
    // Se le inyecta uno mudo para reproducir las condiciones de producción en
    // lugar de agregarle guards al código que sólo harían falta en tests.
    $this->seeder = function (): PositionSeeder {
        $command = Mockery::mock(Command::class);
        $command->shouldIgnoreMissing();

        $seeder = new class($this->dataFile) extends PositionSeeder
        {
            public function __construct(private readonly string $path) {}

            protected function dataPath(): string
            {
                return $this->path;
            }
        };

        $seeder->setCommand($command);

        return $seeder;
    };

    FunctionalArea::factory()->create(['code' => 'quality', 'name' => 'Calidad']);
    FunctionalArea::factory()->create(['code' => 'it_systems', 'name' => 'Sistemas']);
});

afterEach(function (): void {
    @unlink($this->dataFile);
});

it('loads positions from the json file', function (): void {
    ($this->seedWith)(['positions' => [
        ['code' => 'quality_engineer', 'name' => 'Ingeniero de Calidad', 'area' => 'quality'],
        ['code' => 'technical_support', 'name' => 'Soporte Técnico', 'area' => 'it_systems'],
    ]]);

    expect(Position::count())->toBe(2);

    $engineer = Position::where('code', 'quality_engineer')->firstOrFail();
    expect($engineer->name)->toBe('Ingeniero de Calidad')
        ->and($engineer->functional_area_id)->toBe(
            FunctionalArea::where('code', 'quality')->value('id')
        )
        ->and($engineer->sort_order)->toBe(1)
        ->and($engineer->is_active)->toBeTrue();
});

it('pushes legacy positions to the end of the ordering', function (): void {
    ($this->seedWith)(['positions' => [
        ['code' => 'old_role', 'name' => 'Puesto heredado', 'area' => 'it_systems', 'legacy' => true],
        ['code' => 'quality_engineer', 'name' => 'Ingeniero de Calidad', 'area' => 'quality'],
    ]]);

    expect(Position::where('code', 'quality_engineer')->value('sort_order'))->toBe(1)
        ->and(Position::where('code', 'old_role')->value('sort_order'))->toBe(901);
});

it('skips without failing when the file is absent', function (): void {
    ($this->seedWith)(null);

    expect(Position::count())->toBe(0);
});

it('is idempotent and updates names in place', function (): void {
    $payload = ['positions' => [
        ['code' => 'quality_engineer', 'name' => 'Ingeniero de Calidad', 'area' => 'quality'],
    ]];

    ($this->seedWith)($payload);
    $id = Position::where('code', 'quality_engineer')->value('id');

    $payload['positions'][0]['name'] = 'Ingeniero de Calidad Senior';
    ($this->seedWith)($payload);

    expect(Position::count())->toBe(1)
        ->and(Position::where('code', 'quality_engineer')->value('id'))->toBe($id)
        ->and(Position::where('code', 'quality_engineer')->value('name'))
        ->toBe('Ingeniero de Calidad Senior');
});

it('rejects malformed json', function (): void {
    ($this->seedRaw)('{ "positions": [ }');
})->throws(RuntimeException::class, 'no es JSON válido');

it('rejects a payload without the positions key', function (): void {
    ($this->seedWith)(['puestos' => []]);
})->throws(RuntimeException::class, 'clave "positions"');

it('rejects an empty catalog', function (): void {
    ($this->seedWith)(['positions' => []]);
})->throws(RuntimeException::class, 'no tiene ningún puesto');

it('rejects duplicated codes and names both offending rows', function (): void {
    ($this->seedWith)(['positions' => [
        ['code' => 'quality_engineer', 'name' => 'Ingeniero de Calidad', 'area' => 'quality'],
        ['code' => 'quality_engineer', 'name' => 'Otro', 'area' => 'quality'],
    ]]);
})->throws(RuntimeException::class, 'filas 0 y 1');

it('rejects a row missing a required field, pointing at its index', function (): void {
    ($this->seedWith)(['positions' => [
        ['code' => 'quality_engineer', 'name' => 'Ingeniero de Calidad', 'area' => 'quality'],
        ['code' => 'broken', 'area' => 'quality'],
    ]]);
})->throws(RuntimeException::class, 'la fila 1');

it('rejects an area that does not exist in functional_areas', function (): void {
    ($this->seedWith)(['positions' => [
        ['code' => 'astronaut', 'name' => 'Astronauta', 'area' => 'space_program'],
    ]]);
})->throws(RuntimeException::class, 'space_program');

it('reports every unknown area at once instead of one per run', function (): void {
    ($this->seedWith)(['positions' => [
        ['code' => 'astronaut', 'name' => 'Astronauta', 'area' => 'space_program'],
        ['code' => 'sailor', 'name' => 'Marinero', 'area' => 'naval'],
    ]]);
})->throws(RuntimeException::class, 'naval');

it('writes nothing when a later row references an unknown area', function (): void {
    // La fila 0 es válida: sin validación previa quedaría escrita y el catálogo
    // a medio cargar, que es peor que no cargarlo.
    try {
        ($this->seedWith)(['positions' => [
            ['code' => 'quality_engineer', 'name' => 'Ingeniero de Calidad', 'area' => 'quality'],
            ['code' => 'astronaut', 'name' => 'Astronauta', 'area' => 'space_program'],
        ]]);
    } catch (RuntimeException) {
        // esperado
    }

    expect(Position::count())->toBe(0);
});

it('does not persist anything when a later row is invalid', function (): void {
    try {
        ($this->seedWith)(['positions' => [
            ['code' => 'quality_engineer', 'name' => 'Ingeniero de Calidad', 'area' => 'quality'],
            ['code' => '', 'name' => 'Sin código', 'area' => 'quality'],
        ]]);
    } catch (RuntimeException) {
        // Se valida el archivo completo antes de escribir, así que la fila
        // válida tampoco debe haber entrado.
    }

    expect(Position::count())->toBe(0);
});
