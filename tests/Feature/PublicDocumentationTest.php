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

test('the landing page uses the optimized Lina wordmark hero', function () {
    $assetPath = public_path('images/lina-wordmark-hero.webp');
    $pageSource = file_get_contents(resource_path('js/pages/welcome.tsx'));

    expect($assetPath)
        ->toBeFile()
        ->and(filesize($assetPath))
        ->toBeLessThan(100 * 1024)
        ->and($pageSource)
        ->toContain('/images/lina-wordmark-hero.webp')
        ->not->toContain('/images/lina-server-rack.webp');
});

test('the application publishes the Lina favicon set', function () {
    $icons = [
        'favicon.ico',
        'favicon-32x32.png',
        'favicon-192x192.png',
        'apple-touch-icon.png',
    ];

    foreach ($icons as $icon) {
        expect(public_path($icon))->toBeFile();
    }

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('href="/favicon.ico"', false)
        ->assertSee('href="/favicon-32x32.png"', false)
        ->assertSee('href="/favicon-192x192.png"', false)
        ->assertSee('href="/apple-touch-icon.png"', false)
        ->assertDontSee('/favicon.svg', false);
});
