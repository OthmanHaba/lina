<?php

namespace App\Http\Controllers\Servers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\StoreServerRequest;
use App\Models\LogSource;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    /**
     * List the current team's servers.
     */
    public function index(Request $request): Response
    {
        $servers = Server::where('team_id', $request->user()->current_team_id)
            ->latest()
            ->get()
            ->map(fn (Server $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'status' => $server->status(),
                'lastSeenAt' => $server->last_seen_at?->toISOString(),
                'bytesToday' => $server->bytesToday(),
            ]);

        return Inertia::render('servers/index', ['servers' => $servers]);
    }

    /**
     * Create a server and flash its plaintext token exactly once.
     */
    public function store(StoreServerRequest $request): RedirectResponse
    {
        $plain = Str::random(40);

        $server = Server::create([
            'team_id' => $request->user()->current_team_id,
            'name' => $request->validated('name'),
            'token_hash' => hash('sha256', $plain),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server created.')]);

        return to_route('servers.show', ['current_team' => $request->route('current_team'), 'server' => $server->id])
            ->with('plainToken', $plain);
    }

    /**
     * Show a server with its log sources.
     */
    public function show(Request $request, string $current_team, Server $server): Response
    {
        $this->authorizeServer($request, $server);

        return Inertia::render('servers/show', [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'agentVersion' => $server->agent_version,
                'status' => $server->status(),
                'lastSeenAt' => $server->last_seen_at?->toISOString(),
                'bytesToday' => $server->bytesToday(),
            ],
            'sources' => $server->logSources()->get()->map(fn (LogSource $source) => [
                'id' => $source->id,
                'type' => $source->type->value,
                'name' => $source->name,
                'config' => $source->config ?? [],
                'enabled' => $source->enabled,
            ]),
            'plainToken' => session('plainToken'),
            'installBaseUrl' => url('/install'),
        ]);
    }

    /**
     * Regenerate the server's token; the old one stops working immediately.
     */
    public function regenerateToken(Request $request, string $current_team, Server $server): RedirectResponse
    {
        $this->authorizeServer($request, $server);

        $plain = $server->newToken();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token regenerated. Update the server before it goes offline.')]);

        return back()->with('plainToken', $plain);
    }

    /**
     * Delete the server.
     */
    public function destroy(Request $request, string $current_team, Server $server): RedirectResponse
    {
        $this->authorizeServer($request, $server);

        $server->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server deleted.')]);

        return to_route('servers.index', ['current_team' => $current_team]);
    }

    /**
     * Servers are only visible within their own team.
     */
    protected function authorizeServer(Request $request, Server $server): void
    {
        abort_unless($server->team_id === $request->user()->current_team_id, 404);
    }
}
