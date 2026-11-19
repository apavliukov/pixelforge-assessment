<?php

declare(strict_types=1);

use App\Http\Controllers\GenerationEstimatesController;
use App\Http\Controllers\GenerationsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/generations')->name('home');

Route::middleware(['auth'])->group(function (): void {
    Route::get('generations', [GenerationsController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationsController::class, 'store'])->name('generations.store');

    Route::post('generations/estimate', [GenerationEstimatesController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('generationEstimates.store');
});

require __DIR__.'/settings.php';
