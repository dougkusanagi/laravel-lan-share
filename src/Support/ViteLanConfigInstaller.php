<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

final class ViteLanConfigInstaller
{
    public function install(?string $projectRoot = null): string
    {
        $projectRoot ??= base_path();
        $lanConfigPath = $projectRoot.DIRECTORY_SEPARATOR.'vite.lan.config.ts';
        $defaultConfigPath = $projectRoot.DIRECTORY_SEPARATOR.'vite.config.ts';

        if (is_file($lanConfigPath)) {
            return 'exists';
        }

        if (! is_file($defaultConfigPath)) {
            return 'missing-vite-config';
        }

        file_put_contents($lanConfigPath, (string) file_get_contents($defaultConfigPath));

        return 'created';
    }
}
