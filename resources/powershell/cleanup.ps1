#requires -RunAsAdministrator

$ErrorActionPreference = 'Stop'

$RulePrefix = {{ firewall_rule_prefix }}
$LaravelPortStart = {{ laravel_port_start }}
$VitePortStart = {{ vite_port_start }}
$PortSearchLimit = {{ port_search_limit }}
$WslDistro = {{ wsl_distro }}
$StateKey = {{ state_key }}
$StateDirectory = Join-Path $env:LOCALAPPDATA 'DougKusanagi\LaravelLanShare'
$StatePath = Join-Path $StateDirectory ("state-{0}.json" -f $StateKey)
$LegacyStatePath = Join-Path $StateDirectory 'state.json'

function Invoke-Netsh {
    param(
        [string[]] $Arguments,
        [switch] $IgnoreFailure
    )

    & netsh @Arguments 2>$null | Out-Null

    if (-not $IgnoreFailure -and $LASTEXITCODE -ne 0) {
        throw "O comando netsh falhou com o código $LASTEXITCODE."
    }
}

function Remove-PortProxyMapping {
    param([object] $Mapping)

    if ($null -eq $Mapping -or [string]::IsNullOrWhiteSpace([string] $Mapping.listenAddress) -or $null -eq $Mapping.listenPort) {
        return
    }

    Invoke-Netsh -Arguments @(
        'interface', 'portproxy', 'delete', 'v4tov4',
        "listenaddress=$($Mapping.listenAddress)",
        "listenport=$($Mapping.listenPort)"
    ) -IgnoreFailure
}

function Get-StateMappings {
    param([string] $Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        return @()
    }

    try {
        $state = Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
        return @($state.mappings)
    } catch {
        Write-Warning "Não foi possível ler o estado anterior em $Path."
        return @()
    }
}

function Get-WslIp {
    $wslArguments = @()

    if (-not [string]::IsNullOrWhiteSpace($WslDistro)) {
        $wslArguments = @('-d', $WslDistro)
    }

    $output = (& wsl.exe @wslArguments hostname -I 2>$null) -join ' '
    $address = @($output -split '\s+' | Where-Object {
        $_ -match '^\d{1,3}(\.\d{1,3}){3}$' -and $_ -notlike '127.*' -and $_ -notlike '169.254.*'
    }) | Select-Object -First 1

    if ([string]::IsNullOrWhiteSpace($address)) {
        return $null
    }

    return $address
}

function Get-PortProxyMappings {
    $lines = @(& netsh interface portproxy show v4tov4 2>$null)

    foreach ($line in $lines) {
        $text = [string] $line

        if ($text -match '^\s*(?<listenAddress>\d{1,3}(?:\.\d{1,3}){3})\s+(?<listenPort>\d+)\s+(?<connectAddress>\d{1,3}(?:\.\d{1,3}){3})\s+(?<connectPort>\d+)\s*$') {
            [pscustomobject] @{
                listenAddress = $Matches['listenAddress']
                listenPort = [int] $Matches['listenPort']
                connectAddress = $Matches['connectAddress']
                connectPort = [int] $Matches['connectPort']
            }
        }
    }
}

function Test-PortInManagedRange {
    param(
        [int] $Port,
        [int] $Start
    )

    if ($Start -lt 1) {
        return $false
    }

    $end = [Math]::Min(65535, $Start + $PortSearchLimit - 1)

    return $Port -ge $Start -and $Port -le $end
}

function Remove-ManagedResources {
    param([string] $WslIp)

    $statePaths = @($StatePath, $LegacyStatePath) | Select-Object -Unique

    foreach ($path in $statePaths) {
        foreach ($mapping in @(Get-StateMappings -Path $path)) {
            Remove-PortProxyMapping -Mapping $mapping
        }
    }

    if (-not [string]::IsNullOrWhiteSpace($WslIp)) {
        foreach ($mapping in @(Get-PortProxyMappings)) {
            $sameBackend = $mapping.connectAddress -eq $WslIp -and $mapping.connectPort -eq $mapping.listenPort
            $managedPort = (Test-PortInManagedRange -Port $mapping.listenPort -Start $LaravelPortStart) -or
                (Test-PortInManagedRange -Port $mapping.listenPort -Start $VitePortStart)

            if ($sameBackend -and $managedPort) {
                Remove-PortProxyMapping -Mapping $mapping
            }
        }
    }

    Get-NetFirewallRule -Name "$RulePrefix-*" -ErrorAction SilentlyContinue |
        Remove-NetFirewallRule -ErrorAction SilentlyContinue

    foreach ($path in $statePaths) {
        Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
    }
}

$wslIp = Get-WslIp
Remove-ManagedResources -WslIp $wslIp

Write-Host 'Compartilhamento LAN removido.' -ForegroundColor Green
