<?php

use App\Http\Middleware\CorrelationId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $middleware->append(CorrelationId::class);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();
        $correlationId = fn (Request $request): ?string => $request->attributes->get(CorrelationId::ATTRIBUTE);
        $withCorrelation = function (array $body, Request $request) use ($correlationId): array {
            $id = $correlationId($request);

            if ($id) {
                $body['correlation_id'] = $id;
            }

            return $body;
        };

        $exceptions->report(function (\Throwable $e) use ($isApi, $correlationId) {
            $request = request();

            if (!$request instanceof Request || !$isApi($request)) {
                return null;
            }

            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            if ($status < 500) {
                return false;
            }

            Log::error('api.exception', [
                'correlation_id' => $correlationId($request),
                'exception' => $e::class,
                'status' => $status,
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => optional($request->route())->getName(),
                'user_id' => $request->user()?->id,
            ]);

            return false;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi, $withCorrelation) {
            if ($isApi($request)) {
                return response()->json($withCorrelation([
                    'success' => false,
                    'message' => 'No autenticado. Por favor inicia sesion.',
                ], $request), 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi, $withCorrelation) {
            if ($isApi($request)) {
                return response()->json($withCorrelation([
                    'success' => false,
                    'message' => 'Los datos ingresados no son validos.',
                    'errors' => $e->errors(),
                ], $request), 422);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($isApi, $withCorrelation) {
            if ($isApi($request)) {
                return response()->json($withCorrelation([
                    'success' => false,
                    'message' => 'No tienes permiso para realizar esta accion.',
                ], $request), 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($isApi, $withCorrelation) {
            if ($isApi($request)) {
                return response()->json($withCorrelation([
                    'success' => false,
                    'message' => 'El recurso solicitado no fue encontrado.',
                ], $request), 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi, $withCorrelation) {
            if ($isApi($request)) {
                return response()->json($withCorrelation([
                    'success' => false,
                    'message' => 'Ruta no encontrada.',
                ], $request), 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi, $withCorrelation) {
            if ($isApi($request)) {
                $headers = $e->getHeaders();
                $retryAfter = (int) ($headers['Retry-After'] ?? 60);

                return response()->json($withCorrelation([
                    'success' => false,
                    'message' => "Demasiadas solicitudes. Intenta de nuevo en {$retryAfter} segundos.",
                    'retry_after' => $retryAfter,
                ], $request), 429)->withHeaders($headers);
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) use ($isApi, $withCorrelation) {
            if ($isApi($request)) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

                return response()->json($withCorrelation([
                    'success' => false,
                    'message' => $status >= 500
                        ? 'Error interno del servidor.'
                        : 'No se pudo procesar la solicitud.',
                ], $request), $status);
            }
        });
    })->create();
