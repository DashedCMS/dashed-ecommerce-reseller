<?php

namespace Dashed\DashedEcommerceReseller\Http\Middleware;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Dashed\DashedEcommerceReseller\Models\ApiLog;

/**
 * Alleen aanroepen met een herkende gebruiker: een scanner zonder sleutel
 * zou het logboek anders vullen met regels waar niemand iets aan heeft.
 */
class LogResellerApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('reseller_api_started', hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        rescue(function () use ($request, $response) {
            $user = $request->user();

            if (! $user) {
                return;
            }

            $token = $user->currentAccessToken();
            $started = $request->attributes->get('reseller_api_started');

            ApiLog::create([
                'token_id' => $token && method_exists($token, 'getKey') ? $token->getKey() : null,
                'user_id' => $user->getKey(),
                'method' => $request->method(),
                'path' => Str::limit('/' . ltrim($request->path(), '/'), 490, ''),
                'status' => $response->getStatusCode(),
                'duration_ms' => $started ? (int) ((hrtime(true) - $started) / 1_000_000) : 0,
                'ip' => $request->ip(),
            ]);
        }, report: false);
    }
}
