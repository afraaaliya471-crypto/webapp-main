<?php

use App\Http\Middleware\ApiBearerTokenMiddleware;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureGlobalSuperAdmin;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\SecurityHeadersMiddleware;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeadersMiddleware::class);

        $middleware->alias([
            'api.bearer' => ApiBearerTokenMiddleware::class,
            'permission' => CheckPermission::class,
            'super_admin' => EnsureSuperAdmin::class,
            'global_super_admin' => EnsureGlobalSuperAdmin::class,
        ]);

        // No CSRF exemptions — axios sends X-XSRF-TOKEN automatically via withXSRFToken.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
