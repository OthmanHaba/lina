<?php

use App\Models\Server;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the public landing page renders', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('welcome'));
});

test('the primary authenticated interface pages render', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->for($team)->create();

    $pages = [
        [
            route('dashboard', ['current_team' => $team->slug]),
            'dashboard',
        ],
        [
            route('servers.index', ['current_team' => $team->slug]),
            'servers/index',
        ],
        [
            route('servers.show', [
                'current_team' => $team->slug,
                'server' => $server,
            ]),
            'servers/show',
        ],
        [
            route('logs.index', ['current_team' => $team->slug]),
            'logs/index',
        ],
        [
            route('alerts.index', ['current_team' => $team->slug]),
            'alerts/index',
        ],
        [route('profile.edit'), 'settings/profile'],
    ];

    foreach ($pages as [$url, $component]) {
        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page->component($component),
            );
    }
});
