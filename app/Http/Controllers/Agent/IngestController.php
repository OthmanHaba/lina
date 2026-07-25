<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\UsageRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class IngestController extends Controller
{
    /**
     * Forward a raw Loki push from the agent to Loki, scoped to the server's team.
     * The body is never parsed; tenancy comes exclusively from the authenticated token.
     */
    public function __invoke(Request $request): Response
    {
        /** @var Server $server */
        $server = $request->attributes->get('server');

        $body = $request->getContent();

        $this->recordUsage($server, strlen($body));

        try {
            $response = Http::withHeaders([
                'X-Scope-OrgID' => 'team-'.$server->team_id,
            ])->withBody($body, $request->header('Content-Type', 'application/x-protobuf'))
                ->post(rtrim(config('services.loki.url'), '/').'/loki/api/v1/push');

            return response($response->body(), $response->status());
        } catch (ConnectionException) {
            return response('Log storage unavailable', 503);
        }
    }

    /**
     * Accumulate ingested bytes per server per day. insertOrIgnore + increment is
     * race-safe under the (server_id, date) unique index.
     */
    protected function recordUsage(Server $server, int $bytes): void
    {
        UsageRecord::insertOrIgnore([
            'server_id' => $server->id,
            'date' => today()->toDateString(),
            'bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        UsageRecord::where('server_id', $server->id)
            ->whereDate('date', today())
            ->increment('bytes', $bytes);
    }
}
