<?php

namespace App\Http\Controllers\Servers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Response;

class InstallScriptController extends Controller
{
    /**
     * Serve the bash install script. The token is the authentication.
     */
    public function __invoke(string $token): Response
    {
        abort_unless(Server::findByToken($token) instanceof Server, 404);

        $script = view('scripts.install', [
            'token' => $token,
            'baseUrl' => rtrim(config('app.url'), '/'),
        ])->render();

        $script = "#!/usr/bin/env bash\n".$script;

        return response($script, 200, ['Content-Type' => 'text/plain']);
    }
}
