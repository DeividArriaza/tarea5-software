<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationId
{
    public const ATTRIBUTE = 'correlation_id';
    public const HEADER = 'X-Correlation-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->resolveCorrelationId($request);

        $request->attributes->set(self::ATTRIBUTE, $correlationId);
        Log::withContext(['correlation_id' => $correlationId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }

    private function resolveCorrelationId(Request $request): string
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');

        if (preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $incoming)) {
            return $incoming;
        }

        return (string) Str::uuid();
    }
}
