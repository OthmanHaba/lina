<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the public documentation page renders for guests', function () {
    $this->get(route('documentation'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page->component('docs'),
        );
});

test('the public product pages are linked by named routes', function () {
    expect(parse_url(route('home'), PHP_URL_PATH) ?: '/')->toBe('/')
        ->and(route('documentation'))->toEndWith('/docs');
});

test('the repository documentation covers installation usage architecture and operations', function () {
    $documentation = [
        'README.md' => 'Install on a fresh host',
        'docs/INSTALLATION.md' => 'One-command installation',
        'docs/USER_GUIDE.md' => 'Connect a server',
        'docs/ARCHITECTURE.md' => 'Request flows',
        'docs/OPERATIONS.md' => 'Backups',
    ];

    foreach ($documentation as $path => $expectedText) {
        expect(base_path($path))
            ->toBeFile()
            ->and(file_get_contents(base_path($path)))
            ->toContain($expectedText);
    }
});
