<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\SaveAlertRequest;
use App\Models\Alert;
use App\Models\AlertEvent;
use App\Models\LogSource;
use App\Models\NotificationChannel;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    /**
     * The alerts page: alerts, channels, and the filter options they share with logs.
     */
    public function index(Request $request): Response
    {
        $teamId = $request->user()->current_team_id;

        return Inertia::render('alerts/index', [
            'alerts' => Alert::where('team_id', $teamId)
                ->with(['channels:id', 'events' => fn ($query) => $query->latest()->limit(10)])
                ->latest()
                ->get()
                ->map(fn (Alert $alert) => [
                    'id' => $alert->id,
                    'name' => $alert->name,
                    'type' => $alert->type->value,
                    'state' => $alert->state->value,
                    'enabled' => $alert->enabled,
                    'config' => $alert->config,
                    'channelIds' => $alert->channels->pluck('id'),
                    'lastStateChangeAt' => $alert->last_state_change_at?->toISOString(),
                    'events' => $alert->events->map(fn (AlertEvent $event) => [
                        'state' => $event->state->value,
                        'measuredValue' => $event->measured_value,
                        'createdAt' => $event->created_at?->toISOString(),
                    ]),
                ]),
            'channels' => NotificationChannel::where('team_id', $teamId)
                ->orderBy('name')
                ->get()
                ->map(fn (NotificationChannel $channel) => [
                    'id' => $channel->id,
                    'type' => $channel->type->value,
                    'name' => $channel->name,
                    'enabled' => $channel->enabled,
                    'failureCount' => $channel->failure_count,
                    'config' => $channel->config,
                ]),
            'servers' => Server::where('team_id', $teamId)
                ->get(['id', 'name'])
                ->map(fn (Server $server) => ['id' => $server->id, 'name' => $server->name]),
            'sources' => LogSource::whereIn('server_id', Server::where('team_id', $teamId)->select('id'))
                ->where('enabled', true)
                ->distinct()
                ->pluck('name'),
        ]);
    }

    /**
     * Create an alert with attached channels.
     */
    public function store(SaveAlertRequest $request, string $current_team): RedirectResponse
    {
        $alert = Alert::create([
            'team_id' => $request->user()->current_team_id,
            'name' => $request->validated('name'),
            'type' => $request->validated('type'),
            'config' => $request->alertConfig(),
            'enabled' => $request->validated('enabled', true),
        ]);

        $alert->channels()->sync($request->validated('channels', []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert created.')]);

        return back();
    }

    /**
     * Update an alert and its channels.
     */
    public function update(SaveAlertRequest $request, string $current_team, Alert $alert): RedirectResponse
    {
        $this->authorizeAlert($request, $alert);

        $alert->update([
            'name' => $request->validated('name'),
            'type' => $request->validated('type'),
            'config' => $request->alertConfig(),
            'enabled' => $request->validated('enabled', true),
        ]);

        $alert->channels()->sync($request->validated('channels', []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert updated.')]);

        return back();
    }

    /**
     * Delete an alert; events and pivot rows cascade.
     */
    public function destroy(Request $request, string $current_team, Alert $alert): RedirectResponse
    {
        $this->authorizeAlert($request, $alert);

        $alert->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert deleted.')]);

        return back();
    }

    /**
     * Alerts are only visible within their own team.
     */
    protected function authorizeAlert(Request $request, Alert $alert): void
    {
        abort_unless($alert->team_id === $request->user()->current_team_id, 404);
    }
}
