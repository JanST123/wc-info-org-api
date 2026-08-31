<?php

use App\Exceptions\Handler;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\RequestLogMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(CorsMiddleware::class);
        $middleware->append(RequestLogMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Handler::configure($exceptions);
    })->create();
