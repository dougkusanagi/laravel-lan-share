<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use RuntimeException;

final class ScriptFileWriter
{
    public function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Não foi possível criar o diretório {$directory}.");
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Não foi possível salvar o script em {$path}.");
        }
    }
}
