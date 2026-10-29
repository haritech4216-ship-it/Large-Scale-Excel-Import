<?php

use App\Http\Controllers\Api\ImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('imports')->group(function () {
    Route::post('/', [ImportController::class, 'store'])->name('imports.store');
    Route::get('/', [ImportController::class, 'index'])->name('imports.index');
    Route::get('/{id}', [ImportController::class, 'show'])->name('imports.show');
    Route::get('/{id}/errors', [ImportController::class, 'errors'])->name('imports.errors');
    Route::get('/{id}/errors.csv', [ImportController::class, 'downloadErrorsCsv'])->name('imports.errors.csv');
});
