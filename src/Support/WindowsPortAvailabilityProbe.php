<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use Illuminate\Support\Facades\Process;
use Throwable;

final class WindowsPortAvailabilityProbe implements PortAvailabilityProbe
{
    /** @var array<int, true>|null */
    private ?array $busyPorts = null;

    private bool $probeFailed = false;

    public function isAvailable(int $port): ?bool
    {
        if ($this->probeFailed) {
            return null;
        }

        if ($this->busyPorts === null) {
            $this->busyPorts = $this->probeBusyPorts();

            if ($this->busyPorts === null) {
                $this->probeFailed = true;

                return null;
            }
        }

        return ! isset($this->busyPorts[$port]);
    }

    /**
     * @return array<int, true>|null
     */
    private function probeBusyPorts(): ?array
    {
        try {
            $result = Process::timeout(5)->run([
                'powershell.exe',
                '-NoProfile',
                '-NonInteractive',
                '-Command',
                $this->probeCommand(),
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $busyPorts = [];
        $ready = false;

        foreach (preg_split('/\R+/', trim($result->output())) ?: [] as $line) {
            $line = trim($line);

            if ($line === 'ready') {
                $ready = true;

                continue;
            }

            if (preg_match('/^busy:(\d+)$/', $line, $matches) !== 1) {
                continue;
            }

            $busyPorts[(int) $matches[1]] = true;
        }

        return $ready ? $busyPorts : null;
    }

    private function probeCommand(): string
    {
        $wslDistro = trim((string) config('lan-share.wsl_distro'));
        $wslArguments = '@()';

        if ($wslDistro !== '') {
            $wslDistro = str_replace("'", "''", $wslDistro);
            $wslArguments = "@('-d', '{$wslDistro}')";
        }

        return sprintf(
            <<<'POWERSHELL'
$wslArguments = %s;
$wslOutput = (& wsl.exe @wslArguments hostname -I 2>$null) -join ' ';
$wslIps = @($wslOutput -split '\s+' | Where-Object {
    $_ -match '^\d{1,3}(\.\d{1,3}){3}$' -and $_ -notlike '127.*' -and $_ -notlike '169.254.*'
});
$portProxies = @((& netsh interface portproxy show v4tov4 2>$null) | ForEach-Object {
    $line = [string] $_;

    if ($line -match '^\s*(?<listenAddress>\d{1,3}(?:\.\d{1,3}){3})\s+(?<listenPort>\d+)\s+(?<connectAddress>\d{1,3}(?:\.\d{1,3}){3})\s+(?<connectPort>\d+)\s*$') {
        [pscustomobject] @{
            listenPort = [int] $Matches['listenPort'];
            connectAddress = $Matches['connectAddress'];
            connectPort = [int] $Matches['connectPort'];
        }
    }
});
$listeners = @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue);
$blockingPorts = @($listeners | Where-Object {
    $listener = $_;
    $isLoopback = $listener.LocalAddress -like '127.*' -or $listener.LocalAddress -eq '::1';
    $isWslPortProxy = @($portProxies | Where-Object {
        $_.listenPort -eq $listener.LocalPort -and
        $_.connectPort -eq $listener.LocalPort -and
        $wslIps -contains $_.connectAddress
    }).Count -gt 0;

    -not $isLoopback -and -not $isWslPortProxy
} | Select-Object -ExpandProperty LocalPort -Unique);
$blockingPorts | ForEach-Object { Write-Output ("busy:{0}" -f $_) };
Write-Output 'ready';
POWERSHELL,
            $wslArguments,
        );
    }
}
