<?php

namespace Modules\TransactionVerification\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\ServiceProvider;
use Modules\TransactionVerification\Http\Middleware\VerifyServiceCaller;
use Modules\TransactionVerification\Services\TransactionVerificationService;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Wires the module: config, the public TransactionVerifier binding and the OCR seams.
 * The engine is chosen by config: 'tesseract' (the Docker image ships it) or 'null' on a box without it.
 */
class TransactionVerificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/transaction-verification.php', 'transaction-verification');

        $this->app->bind(
            \Modules\TransactionVerification\Contracts\TransactionVerifier::class,
            \Modules\TransactionVerification\Services\TransactionVerificationService::class,
        );

        // Read at resolve time, so a config change (or a test) picks the parser and engine without re-registering.
        $this->app->bind(\Modules\TransactionVerification\Contracts\ReceiptParser::class, fn ($app) => $app->make(config('transaction-verification.parser')));
        $this->app->bind(\Modules\TransactionVerification\Contracts\OcrEngine::class, fn ($app) => $app->make(
            config('transaction-verification.engine') === 'tesseract'
                ? \Modules\TransactionVerification\Services\Ocr\TesseractEngine::class
                : \Modules\TransactionVerification\Services\Ocr\NullEngine::class
        ));
    }

    public function boot(): void
    {
        // An app pins its own values in code: php artisan vendor:publish --tag=transaction-verification-config
        $this->publishes([__DIR__.'/../../config/transaction-verification.php' => config_path('transaction-verification.php')], 'transaction-verification-config');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/internal.php');

        // The router sorts throttles by priority, ahead of middleware it doesn't know: without this the per-key
        // budget would be charged before the signature is checked, and anyone could use up a caller's.
        $kernel = $this->app->make(HttpKernel::class);

        if (method_exists($kernel, 'addToMiddlewarePriorityBefore')) {
            $kernel->addToMiddlewarePriorityBefore([ThrottleRequests::class, ThrottleRequestsWithRedis::class], VerifyServiceCaller::class);
        }
        $this->commands([\Modules\TransactionVerification\Console\ShadowReportCommand::class]);

        // 429s and the router's own 404/405 (a malformed uuid, a wrong method) in the API's JSON envelope too.
        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'renderable')) {
            $handler->renderable(fn (ThrottleRequestsException $e, Request $request) => $request->is('api/internal/transaction-verification/*')
                ? response()->json(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many requests. Please wait a moment and try again.'], 429, $e->getHeaders())
                : null);
            $handler->renderable(fn (NotFoundHttpException|MethodNotAllowedHttpException $e, Request $request) => $request->is('api/internal/transaction-verification/*')
                ? response()->json($e instanceof MethodNotAllowedHttpException
                    ? ['success' => false, 'error' => 'method_not_allowed', 'message' => 'This endpoint does not take that method.']
                    : ['success' => false, 'error' => 'not_found', 'message' => 'No such endpoint.'], $e->getStatusCode(), $e->getHeaders())
                : null);
        }

        // Marks checks that died mid-way failed, so a person looks at them.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->call(fn () => $this->app->make(TransactionVerificationService::class)->failStale())
                ->everyFiveMinutes()->name('transaction-verification-recover')->onOneServer()->withoutOverlapping(10);
        });
    }
}
