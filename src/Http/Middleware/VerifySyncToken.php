<?php

namespace MarceliTo\StatamicSync\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySyncToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('statamic-sync.token');

        if (empty($token)) {
            abort(403, 'Sync token not configured.');
        }

        if ($request->bearerToken() !== $token) {
            abort(403, 'Invalid sync token.');
        }

        // IP whitelist check
        $allowedIps = config('statamic-sync.allowed_ips', []);

        if (! empty($allowedIps) && ! in_array($request->ip(), $allowedIps)) {
            abort(403, 'IP not allowed.');
        }

        return $next($request);
    }
}
