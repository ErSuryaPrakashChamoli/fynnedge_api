<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Token auth only — no session cookies, so no statefulApi() and no
        // CSRF surface.
        //
        // There is no login page to send a guest to. Without this, the auth
        // middleware resolves route('login') before the AuthenticationException
        // is ever raised, and a caller that omits `Accept: application/json`
        // gets a 500 "Route [login] not defined" instead of a 401. Returning
        // null makes it raise the exception, which the renderer below turns
        // into a clean 401 whatever headers the caller sent.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Without this, an unauthenticated API call that omits
        // `Accept: application/json` never reaches the JSON renderer: the
        // auth middleware tries to redirect to a `login` route that does not
        // exist in a token-only API, and the client gets a 500 with a stack
        // trace instead of a 401. A caller must not have to set a header
        // correctly to be told it is unauthenticated.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });
    })->create();
