<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One id per request, in every log line written while it is handled and in the X-Request-Id
 * response header, so a customer's or provider's failed call can be found in the logs. An id
 * sent by a proxy or the app is kept when it looks like one (letters, digits, dashes).
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id');
        $id = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();

        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
