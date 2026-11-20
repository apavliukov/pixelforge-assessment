<?php

declare(strict_types=1);

use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\GenerationEstimatesController;
use App\Http\Controllers\GenerationsController;
use App\Http\Controllers\OperatorReportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/generations')->name('home');

Route::post('demo-login/{demoAccount}', [DemoLoginController::class, 'store'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('demoLogin.store');

Route::middleware(['auth'])->group(function (): void {
    Route::get('generations', [GenerationsController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationsController::class, 'store'])->name('generations.store');

    Route::post('generations/estimate', [GenerationEstimatesController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('generationEstimates.store');

    Route::get('operator/report', [OperatorReportController::class, 'show'])
        ->can('viewOperatorReport')
        ->name('operatorReport.show');
});

require __DIR__.'/settings.php';
