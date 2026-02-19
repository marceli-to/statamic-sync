<?php

use Illuminate\Support\Facades\Route;
use MarceliTo\StatamicSync\Http\Controllers\SyncController;
use MarceliTo\StatamicSync\Http\Middleware\VerifySyncToken;

Route::prefix(config('statamic-sync.route_prefix', '_sync'))
    ->middleware(VerifySyncToken::class)
    ->group(function () {
        Route::get('manifest', [SyncController::class, 'manifest']);
        Route::get('archive', [SyncController::class, 'archive']);
        Route::post('archive-partial', [SyncController::class, 'archivePartial']);
    });
