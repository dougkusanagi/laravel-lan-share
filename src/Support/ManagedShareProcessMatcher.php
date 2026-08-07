<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

final class ManagedShareProcessMatcher
{
    public function matches(
        string $workingDirectory,
        string $commandLine,
        string $projectPath,
        string $viteConfig,
    ): bool {
        $normalizedWorkingDirectory = realpath($workingDirectory) ?: $workingDirectory;
        $normalizedProjectPath = realpath($projectPath) ?: $projectPath;

        if (rtrim($normalizedWorkingDirectory, DIRECTORY_SEPARATOR) !== rtrim($normalizedProjectPath, DIRECTORY_SEPARATOR)) {
            return false;
        }

        $isLaravelServer = str_contains($commandLine, 'php artisan serve --host=0.0.0.0 --port=');
        $isViteServer = str_contains($commandLine, '--config '.$viteConfig.' --host=0.0.0.0 --port=');

        return $isLaravelServer || $isViteServer;
    }
}
