<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\TransactionVerification\Http\Controllers\Internal\VerificationController;
use Modules\TransactionVerification\Http\Middleware\VerifyServiceCaller;

// Per caller key, verified by then (the module provider puts the signature check ahead of the throttles): unlike the
// IP, it can't be spoofed through X-Forwarded-For, and two callers behind one address don't share a budget.
RateLimiter::for('transaction-verification-internal', fn (Request $r) => Limit::perMinute(600)->by('tv-int:'.$r->header('X-Service-Key-Id')));
// Each write runs a whole check in the request (~1-3 s), one at a time on the sidecar.
RateLimiter::for('transaction-verification-internal-write', fn (Request $r) => Limit::perMinute(30)->by('tv-wr:'.$r->header('X-Service-Key-Id')));

Route::prefix('api/internal/transaction-verification/v1')
    ->name('transaction-verification.internal.v1.')
    ->middleware(['api', VerifyServiceCaller::class, 'throttle:transaction-verification-internal'])
    ->group(function () {
        Route::post('verifications', [VerificationController::class, 'store'])->middleware('throttle:transaction-verification-internal-write')->name('verifications.store');
        Route::get('verifications', [VerificationController::class, 'index'])->name('verifications.index');
        Route::get('verifications/{uuid}', [VerificationController::class, 'show'])->whereUuid('uuid')->name('verifications.show');
    });
