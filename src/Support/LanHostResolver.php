<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

final class LanHostResolver
{
    public function resolve(): ?string
    {
        return $this->findFirstAddress(Process::run([
            'powershell.exe',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            '(Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -and $_.IPv4Address }).IPv4Address.IPAddress',
        ]));
    }

    private function findFirstAddress(ProcessResult $result): ?string
    {
        if (! $result->successful()) {
            return null;
        }

        $lines = preg_split('/\R+/', trim($result->output())) ?: [];

        foreach ($lines as $line) {
            $address = trim($line);

            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            if (str_starts_with($address, '127.') || str_starts_with($address, '169.254.')) {
                continue;
            }

            return $address;
        }

        return null;
    }
}
