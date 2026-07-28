<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\AlloyConfigRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AgentConfigController extends Controller
{
    /**
     * Serve the server's rendered Alloy config with ETag support.
     */
    public function __invoke(Request $request, AlloyConfigRenderer $renderer): Response
    {
        /** @var Server $server */
        $server = $request->attributes->get('server');

        $updates = array_filter([
            'hostname' => $request->header('X-Lina-Hostname'),
            'agent_version' => $request->header('X-Lina-Agent-Version'),
        ]);

        if ($server->last_poll_at === null || $server->last_poll_at->lt(now()->subSeconds(30))) {
            $updates['last_poll_at'] = now();
        }

        $config = $renderer->render($server);
        $hash = sha1($config);
        $etag = '"'.$hash.'"';

        if ($request->header('If-None-Match') === $etag) {
            // A 304 is the agent proving it holds the current config.
            if ($server->synced_config_hash !== $hash
                || $server->config_synced_at === null
                || $server->config_synced_at->lt(now()->subSeconds(30))) {
                $updates['synced_config_hash'] = $hash;
                $updates['config_synced_at'] = now();
            }

            $server->forceFill($updates)->save();

            return response('', 304, ['ETag' => $etag]);
        }

        $server->forceFill($updates)->save();

        return response($config, 200, [
            'Content-Type' => 'text/plain',
            'ETag' => $etag,
        ]);
    }
}
