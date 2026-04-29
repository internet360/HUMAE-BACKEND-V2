<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', fn () => response()->json([
    'success' => false,
    'message' => 'No autenticado.',
    'errors' => null,
], 401))->name('login');
