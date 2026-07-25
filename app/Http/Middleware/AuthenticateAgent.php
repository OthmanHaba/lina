<?php

namespace App\Http\Middleware;

use App\Models\Server;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    /**
     * Authenticate an agent request via its per-server bearer token.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $server = Server::findByToken($request->bearerToken());

        if (! $server) {
            return response()->json(['message' => 'Invalid agent token.'], 401);
        }

        $request->attributes->set('server', $server);

        // ponytail: liveness writes throttled to one per 30s per server
        if ($server->last_seen_at === null || $server->last_seen_at->lt(now()->subSeconds(30))) {
            $server->forceFill(['last_seen_at' => now()])->save();
        }

        return $next($request);
    }
}
