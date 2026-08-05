#requires -RunAsAdministrator

$ErrorActionPreference = 'Stop'

$RulePrefix = {{ firewall_rule_prefix }}
$LaravelPort = {{ laravel_port }}
$VitePort = {{ vite_port }}
$WslDistro = {{ wsl_distro }}
$StateDirectory = Join-Path $env:LOCALAPPDATA 'DougKusanagi\LaravelLanShare'
$StatePath = Join-Path $StateDirectory 'state.json'

function Invoke-Netsh {
    param([string[]] $Arguments)

    & netsh @Arguments | Out-Null

    if ($LASTEXITCODE -ne 0) {
        throw "O comando netsh falhou com o código $LASTEXITCODE."
    }
}

function Get-WslIp {
    $wslArguments = @()

    if (-not [string]::IsNullOrWhiteSpace($WslDistro)) {
        $wslArguments = @('-d', $WslDistro)
    }

    $output = (& wsl.exe @wslArguments hostname -I 2>$null) -join ' '
    $address = ($output -split '\s+') | Where-Object { $_ -match '^\d{1,3}(\.\d{1,3}){3}$' } | Select-Object -First 1

    if ([string]::IsNullOrWhiteSpace($address)) {
        throw 'Não foi possível detectar o IP interno do WSL. Verifique se a distribuição está em execução.'
    }

    return $address
}

function Get-LanIp {
    $address = Get-NetIPConfiguration -ErrorAction SilentlyContinue |
        Where-Object { $_.IPv4DefaultGateway -and $_.IPv4Address } |
        ForEach-Object { $_.IPv4Address.IPAddress } |
        Where-Object { $_ -and $_ -notlike '127.*' -and $_ -notlike '169.254.*' } |
        Select-Object -First 1

    if ([string]::IsNullOrWhiteSpace($address)) {
        throw 'Não foi possível detectar um IP LAN ativo no Windows.'
    }

    return $address
}

function Remove-ManagedResources {
    if (Test-Path -LiteralPath $StatePath) {
        $state = Get-Content -LiteralPath $StatePath -Raw | ConvertFrom-Json

        foreach ($mapping in @($state.mappings)) {
            if ($mapping.listenAddress -and $mapping.listenPort) {
                & netsh interface portproxy delete v4tov4 listenaddress=$mapping.listenAddress listenport=$mapping.listenPort | Out-Null
            }
        }

        foreach ($ruleName in @($state.firewallRuleNames)) {
            Remove-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue
        }
    }

    Get-NetFirewallRule -Name "$RulePrefix-*" -ErrorAction SilentlyContinue |
        Remove-NetFirewallRule -ErrorAction SilentlyContinue
}

function Assert-PortAvailable {
    param([int] $Port, [string] $ServiceName)

    $listeners = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue

    if ($listeners) {
        throw "A porta $Port ($ServiceName) já está em uso no Windows. Execute novamente com uma porta diferente."
    }
}

function Test-HttpEndpoint {
    param([string] $Uri)

    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $Uri -TimeoutSec 5
        return $response.StatusCode -ge 200 -and $response.StatusCode -lt 500
    } catch {
        return $false
    }
}

$createdMappings = @()
$createdFirewallRuleNames = @()

try {
    New-Item -ItemType Directory -Path $StateDirectory -Force | Out-Null
    Remove-ManagedResources

    $wslIp = Get-WslIp
    $lanIp = Get-LanIp

    Assert-PortAvailable -Port $LaravelPort -ServiceName 'Laravel'
    Assert-PortAvailable -Port $VitePort -ServiceName 'Vite'

    Invoke-Netsh -Arguments @('interface', 'portproxy', 'add', 'v4tov4', "listenaddress=$lanIp", "listenport=$LaravelPort", "connectaddress=$wslIp", "connectport=$LaravelPort")
    $createdMappings += [ordered]@{ listenAddress = $lanIp; listenPort = $LaravelPort }

    Invoke-Netsh -Arguments @('interface', 'portproxy', 'add', 'v4tov4', "listenaddress=$lanIp", "listenport=$VitePort", "connectaddress=$wslIp", "connectport=$VitePort")
    $createdMappings += [ordered]@{ listenAddress = $lanIp; listenPort = $VitePort }

    $laravelRuleName = "$RulePrefix-Laravel"
    $viteRuleName = "$RulePrefix-Vite"

    Remove-NetFirewallRule -Name $laravelRuleName -ErrorAction SilentlyContinue
    Remove-NetFirewallRule -Name $viteRuleName -ErrorAction SilentlyContinue

    New-NetFirewallRule -Name $laravelRuleName -DisplayName "$RulePrefix Laravel $LaravelPort" -Direction Inbound -Protocol TCP -LocalAddress $lanIp -LocalPort $LaravelPort -RemoteAddress LocalSubnet -Action Allow -Profile Any | Out-Null
    $createdFirewallRuleNames += $laravelRuleName

    New-NetFirewallRule -Name $viteRuleName -DisplayName "$RulePrefix Vite $VitePort" -Direction Inbound -Protocol TCP -LocalAddress $lanIp -LocalPort $VitePort -RemoteAddress LocalSubnet -Action Allow -Profile Any | Out-Null
    $createdFirewallRuleNames += $viteRuleName

    $state = [ordered]@{
        version = 1
        lanIp = $lanIp
        wslIp = $wslIp
        mappings = @(
            [ordered]@{ listenAddress = $lanIp; listenPort = $LaravelPort; connectAddress = $wslIp; connectPort = $LaravelPort }
            [ordered]@{ listenAddress = $lanIp; listenPort = $VitePort; connectAddress = $wslIp; connectPort = $VitePort }
        )
        firewallRuleNames = @($laravelRuleName, $viteRuleName)
    }

    $state | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $StatePath -Encoding UTF8

    $laravelUrl = "http://{0}:{1}" -f $lanIp, $LaravelPort
    $viteUrl = "http://{0}:{1}" -f $lanIp, $VitePort
    $laravelTcpOk = Test-NetConnection -ComputerName $lanIp -Port $LaravelPort -InformationLevel Quiet
    $viteTcpOk = Test-NetConnection -ComputerName $lanIp -Port $VitePort -InformationLevel Quiet
    $laravelHttpOk = Test-HttpEndpoint -Uri "$laravelUrl/up"
    $viteHttpOk = Test-HttpEndpoint -Uri "$viteUrl/@vite/client"

    Write-Host ''
    Write-Host 'Compartilhamento LAN configurado.' -ForegroundColor Green
    Write-Host "Laravel: $laravelUrl"
    Write-Host "Vite:    $viteUrl"
    Write-Host "Estado:  $StatePath"
    Write-Host ''
    Write-Host "TCP Laravel: $(if ($laravelTcpOk) { 'OK' } else { 'FALHOU' })"
    Write-Host "TCP Vite:    $(if ($viteTcpOk) { 'OK' } else { 'FALHOU' })"
    Write-Host "HTTP Laravel: $(if ($laravelHttpOk) { 'OK' } else { 'aguardando o servidor WSL' })"
    Write-Host "HTTP Vite:    $(if ($viteHttpOk) { 'OK' } else { 'aguardando o servidor WSL' })"
    Write-Host ''
    Write-Host 'Para remover, gere e execute: php artisan lan:share:cleanup --script=lan-share-cleanup.ps1'
} catch {
    foreach ($mapping in @($createdMappings)) {
        & netsh interface portproxy delete v4tov4 listenaddress=$mapping.listenAddress listenport=$mapping.listenPort | Out-Null
    }

    foreach ($ruleName in @($createdFirewallRuleNames)) {
        Remove-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue
    }

    Write-Error $_.Exception.Message
    exit 1
}
