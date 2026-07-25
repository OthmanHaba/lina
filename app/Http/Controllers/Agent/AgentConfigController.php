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

        $server->forceFill(array_filter([
            'hostname' => $request->header('X-Lina-Hostname'),
            'agent_version' => $request->header('X-Lina-Agent-Version'),
        ]))->save();

        $config = $renderer->render($server);
        $etag = '"'.sha1($config).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response($config, 200, [
            'Content-Type' => 'text/plain',
            'ETag' => $etag,
        ]);
    }
}
