<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (NotFoundHttpException $e, $request) {
            if ($request->expectsJson()) {
                if ($e->getPrevious() instanceof ModelNotFoundException) {
                    $model = class_basename($e->getPrevious()->getModel());
                    return response()->json([
                        'success' => false,
                        'message' => "{$model} tidak ditemukan",
                        'data' => null
                    ], 404);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Endpoint tidak ditemukan',
                    'data' => null
                ], 404);
            }
        });

        $exceptions->renderable(function (AccessDeniedHttpException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak',
                    'data' => null
                ], 403);
            }
        });

    })
    ->create();
