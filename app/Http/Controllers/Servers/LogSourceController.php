<?php

namespace App\Http\Controllers\Servers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\SaveLogSourceRequest;
use App\Models\LogSource;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LogSourceController extends Controller
{
    /**
     * Add a log source to the server.
     */
    public function store(SaveLogSourceRequest $request, string $current_team, Server $server): RedirectResponse
    {
        $this->authorizeServer($request, $server);

        $server->logSources()->create([
            'type' => $request->validated('type'),
            'name' => $request->validated('name'),
            'config' => $request->sourceConfig(),
            'enabled' => $request->validated('enabled', true),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Log source added. The agent picks it up within a minute.')]);

        return back();
    }

    /**
     * Update a log source.
     */
    public function update(SaveLogSourceRequest $request, string $current_team, Server $server, LogSource $source): RedirectResponse
    {
        $this->authorizeServer($request, $server);
        abort_unless($source->server_id === $server->id, 404);

        $source->update([
            'type' => $request->validated('type'),
            'name' => $request->validated('name'),
            'config' => $request->sourceConfig(),
            'enabled' => $request->validated('enabled', true),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Log source updated.')]);

        return back();
    }

    /**
     * Remove a log source.
     */
    public function destroy(Request $request, string $current_team, Server $server, LogSource $source): RedirectResponse
    {
        $this->authorizeServer($request, $server);
        abort_unless($source->server_id === $server->id, 404);

        $source->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Log source removed.')]);

        return back();
    }

    /**
     * Servers are only visible within their own team.
     */
    protected function authorizeServer(Request $request, Server $server): void
    {
        abort_unless($server->team_id === $request->user()->current_team_id, 404);
    }
}
