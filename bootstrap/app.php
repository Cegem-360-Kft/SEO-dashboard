<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Temporarily disabled custom middleware
        // $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // $middleware->web(append: [
        //     \App\Http\Middleware\EnsureTenantContext::class,
        // ]);
        // $middleware->api(prepend: [
        //     \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        // ]);
        // $middleware->api(append: [
        //     \App\Http\Middleware\ApiTenantMiddleware::class,
        // ]);
        // $middleware->throttleApi();
        // $middleware->alias([
        //     'tenant.permission' => \App\Http\Middleware\TenantPermissionMiddleware::class,
        //     'tenant.context' => \App\Http\Middleware\EnsureTenantContext::class,
        //     'api.tenant' => \App\Http\Middleware\ApiTenantMiddleware::class,
        // ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
