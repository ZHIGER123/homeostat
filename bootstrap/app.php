<?php

use App\Exceptions\BusinessException;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsNotBlocked;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'not_blocked' => EnsureUserIsNotBlocked::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Единый формат ошибок проверки данных для всех API-маршрутов.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Ошибка валидации.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (BusinessException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 409);
            }
        });

        // Единый формат 404: Route Model Binding бросает ModelNotFoundException,
        // который Laravel превращает в NotFoundHttpException. Внутренние детали
        // (имя модели и id) клиенту не показываем.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $message = $e->getPrevious() instanceof ModelNotFoundException
                    || $e->getMessage() === '' || str_starts_with($e->getMessage(), 'The route')
                    ? 'Ресурс не найден.'
                    : $e->getMessage();

                return response()->json(['message' => $message], 404);
            }
        });
    })->create();
