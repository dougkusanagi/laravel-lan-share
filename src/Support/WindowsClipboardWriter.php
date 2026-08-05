<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

final class WindowsClipboardWriter implements ClipboardWriter
{
    public function copy(string $contents): void
    {
        try {
            $result = Process::timeout(5)
                ->input($contents)
                ->run('clip.exe');
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Não foi possível copiar para o clipboard do Windows. Verifique se o WSL está com a interoperabilidade habilitada e se o clip.exe está disponível.',
                previous: $exception,
            );
        }

        if ($result->successful()) {
            return;
        }

        $details = trim($result->errorOutput());
        $message = 'Não foi possível copiar para o clipboard do Windows. Verifique se o WSL está com a interoperabilidade habilitada e se o clip.exe está disponível.';

        if ($details !== '') {
            $message .= " Detalhes: {$details}";
        }

        throw new RuntimeException($message);
    }
}
