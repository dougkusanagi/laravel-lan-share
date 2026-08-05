<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use Illuminate\Support\Facades\Process;
use Throwable;

final class WindowsPortAvailabilityProbe implements PortAvailabilityProbe
{
    public function isAvailable(int $port): ?bool
    {
        try {
            $result = Process::timeout(2)->run([
                'powershell.exe',
                '-NoProfile',
                '-NonInteractive',
                '-Command',
                '$listener = Get-NetTCPConnection -State Listen -LocalPort '.$port.' -ErrorAction SilentlyContinue; if ($null -eq $listener) { Write-Output available } else { Write-Output busy }',
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        return match (strtolower(trim($result->output()))) {
            'available' => true,
            'busy' => false,
            default => null,
        };
    }
}
