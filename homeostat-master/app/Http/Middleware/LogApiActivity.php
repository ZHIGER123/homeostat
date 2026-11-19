<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Безопасный журнал API-запросов: без тела запроса, токенов, паролей и файлов.
 */
class LogApiActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $response = $next($request);

        if ($request->is('api/*')) {
            Log::info('api.request', [
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status' => $response->getStatusCode(),
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);
        }

        return $response;
    }
}
