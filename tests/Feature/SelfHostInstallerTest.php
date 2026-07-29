<?php

use Symfony\Component\Process\Process;

test('the self-host installer is valid bash', function () {
    $process = new Process(['bash', '-n', base_path('install.sh')]);
    $process->run();

    expect($process->isSuccessful())
        ->toBeTrue($process->getErrorOutput())
        ->and(is_executable(base_path('install.sh')))
        ->toBeTrue()
        ->and(is_executable(base_path('docker/production/entrypoint.sh')))
        ->toBeTrue();
});

test('the installer preserves secrets backs up metadata and deploys every runtime service', function () {
    $installer = file_get_contents(base_path('install.sh'));
    $compose = file_get_contents(base_path('docker-compose.production.yml'));

    expect($installer)
        ->toContain('if [ ! -f "$environment_file" ]')
        ->toContain('sqlite3 "$database" ".backup')
        ->toContain('git -C "$install_directory" status --porcelain')
        ->toContain('php artisan migrate --force')
        ->toContain('php artisan loki:write-overrides')
        ->not->toContain('rm -rf')
        ->and($compose)
        ->toContain('app:')
        ->toContain('queue:')
        ->toContain('scheduler:')
        ->toContain('loki:')
        ->toContain('caddy:')
        ->toContain('./database:/var/www/html/database')
        ->toContain('./storage:/var/www/html/storage');
});

test('the production image builds frontend assets and runs an optimized php runtime', function () {
    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $entrypoint = file_get_contents(
        base_path('docker/production/entrypoint.sh'),
    );

    expect($dockerfile)
        ->toContain('FROM node:22-bookworm-slim AS frontend-builder')
        ->toContain('FROM php:8.4-apache-bookworm AS php-base')
        ->toContain('FROM php-base AS runtime')
        ->toContain('npm run build')
        ->toContain('docker-php-ext-install opcache pcntl pdo_sqlite')
        ->and($entrypoint)
        ->toContain('php artisan optimize')
        ->toContain('exec docker-php-entrypoint "$@"');
});
