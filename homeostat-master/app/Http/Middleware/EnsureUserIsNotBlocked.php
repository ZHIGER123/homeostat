<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_blocked) {
            return response()->json(['message' => 'Пользователь заблокирован.'], 403);
        }

        return $next($request);
    }
}
