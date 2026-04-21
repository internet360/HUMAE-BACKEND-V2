<?php

declare(strict_types=1);

it('returns a healthy envelope on /api/v1/health', function () {
    $response = $this->getJson('/api/v1/health');

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'healthy',
        ])
        ->assertJsonPath('data.app', config('app.name'))
        ->assertJsonPath('data.checks.database.ok', true);
});

it('returns JSON envelope on unknown api route', function () {
    $response = $this->getJson('/api/v1/does-not-exist');

    $response
        ->assertStatus(404)
        ->assertJson([
            'success' => false,
            'message' => 'Ruta no encontrada.',
        ]);
});
