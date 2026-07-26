<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\SaveChannelRequest;
use App\Models\NotificationChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NotificationChannelController extends Controller
{
    /**
     * Add a notification channel to the current team.
     */
    public function store(SaveChannelRequest $request, string $current_team): RedirectResponse
    {
        NotificationChannel::create([
            'team_id' => $request->user()->current_team_id,
            'type' => $request->validated('type'),
            'name' => $request->validated('name'),
            'config' => $request->validated('config'),
            'enabled' => $request->validated('enabled', true),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel added.')]);

        return back();
    }

    /**
     * Update a channel. Re-enabling (or any edit) clears the failure streak so a
     * fixed endpoint gets a fresh start.
     */
    public function update(SaveChannelRequest $request, string $current_team, NotificationChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);

        $channel->update([
            'type' => $request->validated('type'),
            'name' => $request->validated('name'),
            'config' => $request->validated('config'),
            'enabled' => $request->validated('enabled', true),
            'failure_count' => 0,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel updated.')]);

        return back();
    }

    /**
     * Delete a channel; pivot rows cascade, alerts remain.
     */
    public function destroy(Request $request, string $current_team, NotificationChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);

        $channel->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel removed.')]);

        return back();
    }

    /**
     * Channels are only visible within their own team.
     */
    protected function authorizeChannel(Request $request, NotificationChannel $channel): void
    {
        abort_unless($channel->team_id === $request->user()->current_team_id, 404);
    }
}
