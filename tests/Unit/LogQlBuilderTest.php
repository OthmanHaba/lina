<?php

use App\Services\LogQlBuilder;

test('builds a selector from servers, sources, and search', function () {
    $query = new LogQlBuilder()->build([1, 2], ['nginx', 'app'], 'error 500');

    expect($query)->toBe('{server_id=~"1|2", source=~"nginx|app"} |= "error 500"');
});

test('omits empty parts', function () {
    expect(new LogQlBuilder()->build([7], [], null))->toBe('{server_id=~"7"}');
});

test('escapes quotes and backslashes in search and sources', function () {
    $query = new LogQlBuilder()->build([1], ['we"ird'], 'say "hi" \\ bye');

    expect($query)->toBe('{server_id=~"1", source=~"we\\"ird"} |= "say \\"hi\\" \\\\ bye"');
});
