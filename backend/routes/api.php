<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\ImportedDataController;
use Illuminate\Support\Facades\Route;

// Public authentication and template routes
Route::post('/auth/login', [AuthController::class, 'login'])->name('login');
Route::get('/imports/sample-template', [ImportController::class, 'downloadSampleTemplate'])->name('imports.sample-template.public');

// Protected authenticated routes
Route::middleware('auth:sanctum')->group(function () {
    // Current user and session management
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

    // Import endpoints protected by authentication & ownership
    Route::prefix('imports')->group(function () {
        Route::get('/sample-template', [ImportController::class, 'downloadSampleTemplate'])->name('imports.sample-template');
        Route::post('/', [ImportController::class, 'store'])->name('imports.store');
        Route::get('/', [ImportController::class, 'index'])->name('imports.index');
        Route::get('/{id}', [ImportController::class, 'show'])->whereNumber('id')->name('imports.show');
        Route::get('/{id}/errors', [ImportController::class, 'errors'])->whereNumber('id')->name('imports.errors');
        Route::get('/{id}/errors.csv', [ImportController::class, 'downloadErrorsCsv'])->whereNumber('id')->name('imports.errors.csv');
    });

    // Imported data management (listing, searching, scoped deletion)
    Route::get('/imported-data', [ImportedDataController::class, 'index'])->name('imported-data.index');
    Route::delete('/imported-data', [ImportedDataController::class, 'destroy'])->name('imported-data.destroy');
});
