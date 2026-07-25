<?php

namespace App\Http\Controllers\Logs;

use App\Http\Controllers\Controller;
use App\Models\LogSource;
use App\Models\Server;
use App\Services\LogQlBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class LogController extends Controller
{
    /**
     * The log explorer page with the team's filter options.
     */
    public function index(Request $request): Response
    {
        $teamId = $request->user()->current_team_id;

        return Inertia::render('logs/index', [
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
     * Query Loki for the current team. Server ids are intersected with the
     * team's servers, so tenancy is enforced twice: here and via X-Scope-OrgID.
     */
    public function query(Request $request, LogQlBuilder $builder): JsonResponse
    {
        $validated = $request->validate([
            'servers' => ['sometimes', 'string', 'max:500'],
            'sources' => ['sometimes', 'string', 'max:500'],
            'search' => ['sometimes', 'nullable', 'string', 'max:500'],
            'start' => ['sometimes', 'integer', 'min:0'],
            'end' => ['sometimes', 'integer', 'min:0'],
        ]);

        $teamId = $request->user()->current_team_id;
        $teamServerIds = Server::where('team_id', $teamId)->pluck('id')->all();

        $requested = array_filter(array_map(intval(...), explode(',', $validated['servers'] ?? '')));
        $serverIds = $requested === [] ? $teamServerIds : array_values(array_intersect($requested, $teamServerIds));

        if ($serverIds === []) {
            return response()->json(['entries' => []]);
        }

        $sources = array_values(array_filter(explode(',', $validated['sources'] ?? '')));

        try {
            $response = Http::withHeaders(['X-Scope-OrgID' => 'team-'.$teamId])
                ->get(rtrim(config('services.loki.url'), '/').'/loki/api/v1/query_range', [
                    'query' => $builder->build($serverIds, $sources, $validated['search'] ?? null),
                    'start' => ($validated['start'] ?? now()->subHour()->timestamp) * 1_000_000_000,
                    'end' => ($validated['end'] ?? now()->timestamp) * 1_000_000_000,
                    'limit' => 500,
                    'direction' => 'backward',
                ]);
        } catch (ConnectionException) {
            return response()->json(['entries' => [], 'error' => 'Log storage is unavailable right now.'], 502);
        }

        if ($response->failed()) {
            return response()->json(['entries' => [], 'error' => 'Log storage is unavailable right now.'], 502);
        }

        $entries = collect($response->json('data.result', []))
            ->flatMap(fn (array $stream) => collect($stream['values'])->map(fn (array $value) => [
                'ts' => $value[0],
                'time' => Carbon::createFromTimestampMs((int) ($value[0] / 1_000_000))->toISOString(),
                'line' => $value[1],
                'labels' => $stream['stream'],
            ]))
            // Loki's `backward` direction sorts within each stream only; merging streams needs this final sort.
            ->sortByDesc('ts')
            ->values();

        return response()->json(['entries' => $entries]);
    }
}
