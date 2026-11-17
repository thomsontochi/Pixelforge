<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome', [
    // Shown on the landing page so reviewers can log straight in. Off unless DEMO_LOGIN_HINT=true.
    'demoLogin' => config('app.demo_login_hint') ? ['email' => 'demo@pixelforge.test', 'password' => 'pixelforge-demo'] : null,
]))->name('home');

Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', '/generate')->name('dashboard');

    Route::get('generate', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditController::class, 'store'])->name('credits.store');

    Route::get('admin/report', ReportController::class)->middleware('can:admin')->name('admin.report');
});

// Pixelforge calls this. It has no session or CSRF token, so it is authenticated by its signature instead.
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

require __DIR__.'/settings.php';
