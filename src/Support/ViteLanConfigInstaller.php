<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use RuntimeException;

final class ViteLanConfigInstaller
{
    public function install(?string $projectRoot = null): string
    {
        $projectRoot ??= base_path();
        $lanConfigPath = $projectRoot.DIRECTORY_SEPARATOR.'vite.lan.config.ts';
        $alternativeLanConfigPath = $projectRoot.DIRECTORY_SEPARATOR.'vite.config.lan.ts';
        $defaultConfigPath = $projectRoot.DIRECTORY_SEPARATOR.'vite.config.ts';

        if (is_file($lanConfigPath)) {
            if (! is_file($defaultConfigPath) || ! $this->isLegacyCopiedConfig($lanConfigPath, $defaultConfigPath)) {
                return 'exists';
            }

            $this->writeLanConfig($lanConfigPath);

            return 'updated';
        }

        if (is_file($alternativeLanConfigPath)) {
            return 'exists';
        }

        if (! is_file($defaultConfigPath)) {
            return 'missing-vite-config';
        }

        $this->writeLanConfig($lanConfigPath);

        return 'created';
    }

    private function isLegacyCopiedConfig(string $lanConfigPath, string $defaultConfigPath): bool
    {
        $lanConfig = file_get_contents($lanConfigPath);
        $defaultConfig = file_get_contents($defaultConfigPath);

        return is_string($lanConfig) && is_string($defaultConfig) && $lanConfig === $defaultConfig;
    }

    private function writeLanConfig(string $lanConfigPath): void
    {
        if (file_put_contents($lanConfigPath, $this->renderLanConfig()) === false) {
            throw new RuntimeException('Não foi possível criar o arquivo vite.lan.config.ts. Verifique as permissões do projeto.');
        }
    }

    private function renderLanConfig(): string
    {
        return <<<'TYPESCRIPT'
import { mergeConfig, defineConfig } from 'vite';
import baseConfig from './vite.config';

const lanOrigin = process.env.VITE_DEV_ORIGIN;
const appOrigin = process.env.APP_URL;

export default defineConfig(async (configEnv) => {
    const resolvedBaseConfig =
        typeof baseConfig === 'function'
            ? await baseConfig(configEnv)
            : baseConfig;

    return mergeConfig(resolvedBaseConfig, {
        server: {
            host: '0.0.0.0',
            strictPort: true,
            ...(lanOrigin ? { origin: lanOrigin } : {}),
            cors: appOrigin ? { origin: appOrigin } : true,
        },
    });
});
TYPESCRIPT;
    }
}
