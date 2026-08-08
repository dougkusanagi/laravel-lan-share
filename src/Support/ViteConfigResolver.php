<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

final class ViteConfigResolver
{
    public function resolve(?string $projectRoot = null, ?string $configured = null): string
    {
        $projectRoot ??= base_path();
        $configured ??= trim((string) config('lan-share.vite_config', 'vite.lan.config.ts'));

        if ($configured === '') {
            return 'vite.config.ts';
        }

        $candidates = [$configured];

        if ($configured === 'vite.lan.config.ts') {
            $candidates[] = 'vite.config.lan.ts';
        }

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($projectRoot.DIRECTORY_SEPARATOR.$candidate)) {
                return $candidate;
            }
        }

        return 'vite.config.ts';
    }
}
