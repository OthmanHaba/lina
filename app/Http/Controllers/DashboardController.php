<?php

namespace App\Http\Controllers;

use App\Models\DashboardWidget;
use App\Models\LogSource;
use App\Models\Server;
use App\Models\TeamInvitation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $email = strtolower($request->user()->email);
        $teamId = $request->user()->current_team_id;

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        return Inertia::render('dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'widgets' => DashboardWidget::where('team_id', $teamId)
                ->orderBy('position')
                ->get()
                ->map(fn (DashboardWidget $widget) => [
                    'id' => $widget->id,
                    'type' => $widget->type->value,
                    'title' => $widget->title,
                    'size' => $widget->size->value,
                    'position' => $widget->position,
                    'config' => $widget->config,
                ])
                ->all(),
            'servers' => Server::where('team_id', $teamId)->get(['id', 'name'])
                ->map(fn (Server $server) => ['id' => $server->id, 'name' => $server->name])
                ->all(),
            'sources' => LogSource::whereIn('server_id', Server::where('team_id', $teamId)->select('id'))
                ->where('enabled', true)->distinct()->pluck('name')->all(),
        ]);
    }
}
