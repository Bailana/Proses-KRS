<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $response = $next($request);
        $durationMs = (microtime(true) - $start) * 1000;

        // Log only API requests to keep storage clean
        if ($request->is('api/*')) {
            $logLevel = $response->getStatusCode() >= 500 ? 'error' : 'info';

            Log::channel('api')->log($logLevel, '[API] '.$request->method().' '.$request->path(), [
                'ip' => $request->ip(),
                'status' => $response->getStatusCode(),
                'duration_ms' => round($durationMs, 2),
                'query_string' => $request->getQueryString(),
                'content_type' => $request->header('Content-Type'),
                'user_agent' => $request->userAgent(),
            ]);
        }

        $response->headers->set('X-Response-Time', round($durationMs, 2).'ms');

        return $response;
    }
}
