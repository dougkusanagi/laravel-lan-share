<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

final class StateKeyResolver
{
    public function resolve(string $projectPath): string
    {
        $normalizedPath = realpath($projectPath) ?: $projectPath;
        $normalizedPath = str_replace('\\', '/', rtrim($normalizedPath, '/\\'));

        return substr(hash('sha256', $normalizedPath), 0, 16);
    }
}
