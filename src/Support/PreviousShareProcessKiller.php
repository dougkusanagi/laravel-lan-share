<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

final class PreviousShareProcessKiller
{
    public function __construct(
        private readonly ManagedShareProcessMatcher $matcher,
    ) {}

    public function stop(string $projectPath, string $viteConfig): int
    {
        if (PHP_OS_FAMILY === 'Windows'
            || ! function_exists('posix_getpgid')
            || ! function_exists('posix_getpgrp')
            || ! function_exists('posix_kill')
            || ! is_dir('/proc')) {
            return 0;
        }

        $processGroups = [];
        $currentProcessGroup = posix_getpgrp();

        foreach (glob('/proc/[0-9]*') ?: [] as $processDirectory) {
            $processId = (int) basename($processDirectory);

            if ($processId <= 0 || $processId === getmypid()) {
                continue;
            }

            $workingDirectory = @readlink($processDirectory.'/cwd');
            $commandLine = @file_get_contents($processDirectory.'/cmdline');

            if (! is_string($workingDirectory) || ! is_string($commandLine)) {
                continue;
            }

            $commandLine = str_replace("\0", ' ', $commandLine);

            if (! $this->matcher->matches($workingDirectory, $commandLine, $projectPath, $viteConfig)) {
                continue;
            }

            $processGroup = posix_getpgid($processId);

            if (! is_int($processGroup) || $processGroup <= 0 || $processGroup === $currentProcessGroup) {
                continue;
            }

            $processGroups[$processGroup] = true;
        }

        $stoppedGroups = 0;

        foreach (array_keys($processGroups) as $processGroup) {
            if ($this->stopProcessGroup((int) $processGroup)) {
                $stoppedGroups++;
            }
        }

        return $stoppedGroups;
    }

    private function stopProcessGroup(int $processGroup): bool
    {
        $terminationSignal = defined('SIGTERM') ? SIGTERM : 15;
        $killSignal = defined('SIGKILL') ? SIGKILL : 9;

        if (! @posix_kill(-$processGroup, $terminationSignal)) {
            return false;
        }

        for ($attempt = 0; $attempt < 20; $attempt++) {
            if (! @posix_kill(-$processGroup, 0)) {
                return true;
            }

            usleep(100_000);
        }

        @posix_kill(-$processGroup, $killSignal);

        return true;
    }
}
