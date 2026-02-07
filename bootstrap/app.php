<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware) {

        // Disable CSRF for admin APIs
        $middleware->validateCsrfTokens(except: [
            
            'admin/*',
            'vendor/*',
            'customer/*',
             'delivery/*',
        ]);

        // Never redirect guests (API-only)
        $middleware->redirectGuestsTo(fn () => null);
    })

    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (
            AuthenticationException $e,
            Request $request
        ) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        });
    })

    ->create();
