<?php

use App\Http\Controllers\AISettingsController;
use App\Http\Controllers\CandidatureController;
use App\Http\Controllers\CvAdminController;
use App\Http\Controllers\CvPublicController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CvPublicController::class, 'home'])->name('home');
Route::get('robots.txt', [CvPublicController::class, 'robots'])->name('seo.robots');
Route::get('sitemap.xml', [CvPublicController::class, 'sitemap'])->name('seo.sitemap');
Route::get('llms.txt', [CvPublicController::class, 'llms'])->name('seo.llms');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [CandidatureController::class, 'index'])->name('dashboard');
    Route::get('offres/france-travail', [CandidatureController::class, 'searchOffers'])->middleware(['can:generate-candidature', 'throttle:20,1'])->name('offers.search');

    Route::name('candidatures.')->prefix('candidatures')->group(function () {
        Route::post('/', [CandidatureController::class, 'store'])->middleware(['can:generate-candidature', 'throttle:candidature'])->name('store');
        Route::get('{candidature}', [CandidatureController::class, 'show'])->whereUuid('candidature')->name('show');
        Route::post('{candidature}/retry', [CandidatureController::class, 'retry'])->whereUuid('candidature')->middleware(['can:generate-candidature', 'throttle:candidature'])->name('retry');
        Route::patch('{candidature}', [CandidatureController::class, 'update'])->whereUuid('candidature')->middleware('throttle:10,1')->name('update');
        Route::patch('{candidature}/sent', [CandidatureController::class, 'markSent'])->whereUuid('candidature')->name('sent');
        Route::patch('{candidature}/tracking', [CandidatureController::class, 'move'])->whereUuid('candidature')->name('move');
        Route::delete('{candidature}', [CandidatureController::class, 'destroy'])->whereUuid('candidature')->name('destroy');
        Route::get('{candidature}/download/{document}/{format}', [CandidatureController::class, 'download'])->whereUuid('candidature')
            ->whereIn('document', ['cv', 'letter'])->whereIn('format', ['pdf', 'docx'])->middleware('throttle:candidature-documents')->name('download');
    });

    Route::prefix('settings/ai')->name('ai-settings.')->middleware('can:generate-candidature')->group(function () {
        Route::get('/', [AISettingsController::class, 'edit'])->middleware('password.confirm')->name('edit');
        Route::put('/', [AISettingsController::class, 'update'])->middleware(['password.confirm', 'throttle:6,1'])->name('update');
        Route::delete('/', [AISettingsController::class, 'destroy'])->middleware(['password.confirm', 'throttle:6,1'])->name('destroy');
    });

    Route::prefix('administration')->name('admin.')->middleware('can:manage-cv-site')->group(function () {
        Route::get('/', [CvAdminController::class, 'index'])->name('index');
        Route::patch('users/{user}', [CvAdminController::class, 'role'])->middleware(['password.confirm', 'throttle:10,1'])->name('role');
        Route::put('seo', [CvAdminController::class, 'seo'])->middleware(['password.confirm', 'throttle:10,1'])->name('seo');
    });
});

require __DIR__.'/settings.php';
