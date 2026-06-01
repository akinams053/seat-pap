<?php

namespace Seat\Kassie\Calendar\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 写接口（debit / refund）专用 token 中间件。
 *
 * 与只读 calendar.api.token 分开存、可单独轮换：写 token 泄露不影响
 * 只读余额查询，只读 token 泄露也无法扣款。读 kassie.calendar.api_write_token。
 */
class ApiWriteTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $configuredToken = setting('kassie.calendar.api_write_token', true);

        if (! $configuredToken) {
            return response()->json(['status' => 'error', 'message' => 'Write API not configured.'], 503);
        }

        // 支持 Bearer token 和 query 参数两种方式
        $token = $request->bearerToken() ?? $request->query('token');

        if (! $token || ! hash_equals($configuredToken, $token)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
