<?php

use DougKusanagi\LaravelLanShare\Support\PackageManagerResolver;
use Symfony\Component\Process\ExecutableFinder;

it('prioriza Bun ao resolver o gerenciador de pacotes JavaScript', function () {
    $finder = new class extends ExecutableFinder
    {
        public function find(string $name, ?string $default = null, array $extraDirs = []): ?string
        {
            return match ($name) {
                'bun' => '/opt/bin/bun',
                'pnpm' => '/opt/bin/pnpm',
                'npm' => '/opt/bin/npm',
                default => $default,
            };
        }
    };

    expect((new PackageManagerResolver($finder))->resolve())
        ->toBe(['name' => 'bun', 'command' => '/opt/bin/bun']);
});
