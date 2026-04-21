<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'career_levels',
        'degree_levels',
        'functional_areas',
        'vacancy_categories',
        'vacancy_types',
        'vacancy_shifts',
        'vacancy_tags',
        'positions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
                $t->string('code', 80)->unique();
                $t->string('name', 160);
                $t->string('description', 500)->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
