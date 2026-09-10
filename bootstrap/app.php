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

        /*
         * Who is allowed to tell FynnEdge who the customer is.
         *
         * Every HTTPS deployment puts something in front of PHP, and until
         * that something is trusted, Laravel reads the proxy's own address
         * as the client's. Measured: two customers arriving through one
         * load balancer both present as 10.0.0.5, and request->secure()
         * reports false while the customer is on TLS.
         *
         * That is not cosmetic. The OTP limiters key partly on the client
         * address, so an untrusted proxy collapses the whole customer base
         * into one bucket — sixty sends a minute shared by everybody — and
         * the first busy morning locks real people out of logging in.
         *
         * Left empty on purpose. Trusting every proxy is the other failure:
         * X-Forwarded-For is a request header like any other, so an app
         * reachable outside its load balancer would let any caller claim
         * any address and step around the per-address limits entirely.
         * Which proxies to trust is a fact about a deployment that only the
         * deployment knows, so it is configuration rather than a default,
         * and TRUSTED_PROXIES is documented in .env.example.
         */
        $proxies = env('TRUSTED_PROXIES');

        if (filled($proxies)) {
            $middleware->trustProxies(
                at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)),
            );
        }
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
