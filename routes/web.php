<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pronomix\BookStackOpenWebUISync\Http\Controllers\SettingsController;

Route::middleware(['web', 'openwebui.admin'])
    ->prefix('settings/openwebui')
    ->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('openwebui.settings.index');
        Route::post('/', [SettingsController::class, 'update'])->name('openwebui.settings.update');
        Route::post('/test', [SettingsController::class, 'test'])->name('openwebui.settings.test');
        Route::post('/sync/all', [SettingsController::class, 'syncAll'])->name('openwebui.settings.sync_all');
        Route::post('/sync/book', [SettingsController::class, 'syncBook'])->name('openwebui.settings.sync_book');
        Route::post('/rebuild/book', [SettingsController::class, 'rebuildBook'])->name('openwebui.settings.rebuild_book');
    });
