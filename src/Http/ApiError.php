<?php

namespace Dashed\DashedEcommerceReseller\Http;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Eén foutvorm voor de hele API. De teksten zijn voor een ontwikkelaar bij
 * de afnemer, niet voor de beheeromgeving, en daarom geen __().
 */
final class ApiError
{
    public static function response(string $code, string $message, int $status, array $headers = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }

    public static function matches(Request $request): bool
    {
        return $request->is('api/reseller/*');
    }

    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof AuthenticationException => self::response('unauthenticated', 'Geen geldige API-sleutel.', 401),
            $e instanceof ValidationException => self::response('invalid_parameters', (string) $e->validator->errors()->first(), 422),
            $e instanceof ThrottleRequestsException => self::response('rate_limited', 'Te veel verzoeken, probeer het zo opnieuw.', 429, $e->getHeaders()),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => self::response('not_found', 'Niet gevonden.', 404),
            $e instanceof HttpExceptionInterface => self::response('http_error', $e->getMessage() ?: 'Verzoek geweigerd.', $e->getStatusCode(), $e->getHeaders()),
            default => self::response('server_error', 'Er ging iets mis aan onze kant.', 500),
        };
    }
}
