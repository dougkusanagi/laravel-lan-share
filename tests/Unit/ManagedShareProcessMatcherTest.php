<?php

use DougKusanagi\LaravelLanShare\Support\ManagedShareProcessMatcher;

it('matches only LAN Share server processes from the current project', function () {
    $matcher = new ManagedShareProcessMatcher;
    $projectPath = getcwd();

    expect($matcher->matches(
        $projectPath,
        'sh -c npx concurrently "php artisan serve --host=0.0.0.0 --port=9876" "npm run dev -- --config vite.lan.config.ts --host=0.0.0.0 --port=9877"',
        $projectPath,
        'vite.lan.config.ts',
    ))->toBeTrue()
        ->and($matcher->matches(
            $projectPath,
            'php artisan serve --host=127.0.0.1 --port=8000',
            $projectPath,
            'vite.lan.config.ts',
        ))->toBeFalse()
        ->and($matcher->matches(
            dirname($projectPath),
            'php artisan serve --host=0.0.0.0 --port=9876',
            $projectPath,
            'vite.lan.config.ts',
        ))->toBeFalse();
});
