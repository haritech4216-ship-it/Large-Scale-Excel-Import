<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Interactive Swagger UI documentation
Route::get('/docs', function () {
    return view('swagger');
})->name('docs.index');

// Serve OpenAPI 3.0.3 specification file
Route::get('/docs/openapi.yaml', function () {
    $specPath = realpath(base_path('../docs/openapi.yaml'));

    if (! $specPath || ! is_file($specPath)) {
        abort(404, 'OpenAPI specification not found.');
    }

    return response(file_get_contents($specPath), 200, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
        'Cache-Control' => 'no-cache, must-revalidate',
    ]);
})->name('docs.openapi');
