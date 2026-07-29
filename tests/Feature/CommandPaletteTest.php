<?php

test('the authenticated layout mounts the command palette', function () {
    $header = file_get_contents(
        resource_path('js/components/app-sidebar-header.tsx'),
    );
    $palette = file_get_contents(
        resource_path('js/components/command-palette.tsx'),
    );

    expect($header)
        ->toContain("import CommandPalette from '@/components/command-palette'")
        ->toContain('<CommandPalette />')
        ->and($palette)
        ->toContain("event.key.toLowerCase() === 'k'")
        ->toContain('event.metaKey || event.ctrlKey')
        ->toContain('aria-keyshortcuts="Control+K Meta+K"')
        ->toContain('role="combobox"')
        ->toContain('role="listbox"')
        ->toContain('router.visit(command.href)');
});
