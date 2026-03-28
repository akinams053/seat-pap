<?php

namespace Seat\Kassie\Calendar\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $configuredToken = setting('kassie.calendar.api_token', true);

        if (!$configuredToken) {
            return response()->json(['status' => 'error', 'message' => 'API not configured.'], 503);
        }

        // 支持 Bearer token 和 query 参数两种方式
        $token = $request->bearerToken() ?? $request->query('token');

        if (!$token || !hash_equals($configuredToken, $token)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
