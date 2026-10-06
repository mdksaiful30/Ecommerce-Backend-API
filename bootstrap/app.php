<?php

use App\Http\Middleware\AdminMiddleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register the "admin" middleware alias.
        $middleware->alias([
            'admin' => AdminMiddleware::class,
        ]);

        // API-only app: never redirect guests to a "login" web route.
        // Returning null makes the exception handler respond with a JSON 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always return JSON for API requests instead of redirecting
        // to a "login" route (which does not exist in an API-only app).
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // Route model binding miss (e.g. /api/products/2/images when product 2
        // does not exist). Laravel converts ModelNotFoundException into a
        // NotFoundHttpException *before* custom renderers run, so we inspect
        // the previous exception and return a clean message instead of the raw
        // "No query results for model [App\Models\Product] 2".
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            $previous = $e->getPrevious();

            if ($previous instanceof ModelNotFoundException && ($request->is('api/*') || $request->expectsJson())) {
                return response()->json([
                    'message' => class_basename($previous->getModel()).' not found.',
                ], 404);
            }
        });
    })->create();
