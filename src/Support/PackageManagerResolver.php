<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

final class PackageManagerResolver
{
    public function __construct(private readonly ?ExecutableFinder $finder = null) {}

    /**
     * @return array{name: string, command: string}
     */
    public function resolve(): array
    {
        $finder = $this->finder ?? new ExecutableFinder;

        foreach (['bun', 'pnpm', 'npm'] as $name) {
            $path = $finder->find($name, null, $this->extraDirectories());

            if ($path !== null) {
                return [
                    'name' => $name,
                    'command' => $this->quote($path),
                ];
            }
        }

        throw new RuntimeException('Nenhum gerenciador de pacotes JavaScript foi encontrado. Instale Bun, pnpm ou npm.');
    }

    /**
     * @return list<string>
     */
    private function extraDirectories(): array
    {
        $home = trim((string) getenv('HOME'));
        $bunInstall = trim((string) getenv('BUN_INSTALL'));

        return array_values(array_unique(array_filter([
            $bunInstall === '' ? null : rtrim($bunInstall, '/\\').'/bin',
            $home === '' ? null : rtrim($home, '/\\').'/.bun/bin',
        ], is_string(...))));
    }

    private function quote(string $path): string
    {
        return preg_match('/^[A-Za-z0-9._\\/-]+$/', $path) === 1
            ? $path
            : escapeshellarg($path);
    }
}
