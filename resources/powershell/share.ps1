#requires -RunAsAdministrator

$ErrorActionPreference = 'Stop'

$RulePrefix = {{ firewall_rule_prefix }}
$LaravelPort = {{ laravel_port }}
$VitePort = {{ vite_port }}
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

function Get-WslIp {
    $wslArguments = @()

    if (-not [string]::IsNullOrWhiteSpace($WslDistro)) {
        $wslArguments = @('-d', $WslDistro)
    }

    $output = (& wsl.exe @wslArguments hostname -I 2>$null) -join ' '
    $addresses = @($output -split '\s+' | Where-Object {
        $_ -match '^\d{1,3}(\.\d{1,3}){3}$' -and $_ -notlike '127.*' -and $_ -notlike '169.254.*'
    })
    $address = $addresses | Select-Object -First 1

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

    # portproxy has no owner/name metadata. Remove only orphaned entries that
    # point to this WSL instance and fall inside LAN Share's managed ranges.
    foreach ($mapping in @(Get-PortProxyMappings)) {
        $sameBackend = $mapping.connectAddress -eq $WslIp -and $mapping.connectPort -eq $mapping.listenPort
        $managedPort = (Test-PortInManagedRange -Port $mapping.listenPort -Start $LaravelPortStart) -or
            (Test-PortInManagedRange -Port $mapping.listenPort -Start $VitePortStart)

        if ($sameBackend -and $managedPort) {
            Remove-PortProxyMapping -Mapping $mapping
        }
    }

    Get-NetFirewallRule -Name "$RulePrefix-*" -ErrorAction SilentlyContinue |
        Remove-NetFirewallRule -ErrorAction SilentlyContinue

    foreach ($path in $statePaths) {
        Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
    }
}

function Assert-PortAvailable {
    param(
        [int] $Port,
        [string] $ServiceName,
        [string] $ListenAddress
    )

    $listeners = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
    $blockingListeners = @($listeners | Where-Object {
        $_.LocalAddress -notlike '127.*' -and $_.LocalAddress -ne '::1' -and
        ($_.LocalAddress -eq '0.0.0.0' -or $_.LocalAddress -eq '::' -or $_.LocalAddress -eq $ListenAddress)
    })

    if ($blockingListeners.Count -gt 0) {
        throw "A porta $Port ($ServiceName) já está em uso no Windows no endereço $ListenAddress. Execute novamente com uma porta diferente."
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
$temporaryStatePath = "$StatePath.$PID.tmp"

try {
    New-Item -ItemType Directory -Path $StateDirectory -Force | Out-Null

    $wslIp = Get-WslIp
    $lanIp = Get-LanIp
    $isMirroredNetwork = $wslIp -eq $lanIp
    $listenAddress = '0.0.0.0'
    Remove-ManagedResources -WslIp $wslIp

    Assert-PortAvailable -Port $LaravelPort -ServiceName 'Laravel' -ListenAddress $listenAddress
    Assert-PortAvailable -Port $VitePort -ServiceName 'Vite' -ListenAddress $listenAddress

    if (-not $isMirroredNetwork) {
    Invoke-Netsh -Arguments @(
        'interface', 'portproxy', 'add', 'v4tov4',
        "listenaddress=$listenAddress", "listenport=$LaravelPort",
        "connectaddress=$wslIp", "connectport=$LaravelPort"
    )
    $createdMappings += [ordered] @{
        listenAddress = $listenAddress
        listenPort = $LaravelPort
        connectAddress = $wslIp
        connectPort = $LaravelPort
    }

    Invoke-Netsh -Arguments @(
        'interface', 'portproxy', 'add', 'v4tov4',
        "listenaddress=$listenAddress", "listenport=$VitePort",
        "connectaddress=$wslIp", "connectport=$VitePort"
    )
    $createdMappings += [ordered] @{
        listenAddress = $listenAddress
        listenPort = $VitePort
        connectAddress = $wslIp
        connectPort = $VitePort
    }
    }

    $laravelRuleName = "$RulePrefix-Laravel"
    $viteRuleName = "$RulePrefix-Vite"

    Remove-NetFirewallRule -Name $laravelRuleName -ErrorAction SilentlyContinue
    Remove-NetFirewallRule -Name $viteRuleName -ErrorAction SilentlyContinue

    New-NetFirewallRule -Name $laravelRuleName -DisplayName "$RulePrefix Laravel $LaravelPort" -Direction Inbound -Protocol TCP -LocalAddress $lanIp -LocalPort $LaravelPort -RemoteAddress LocalSubnet -Action Allow -Profile Any | Out-Null
    $createdFirewallRuleNames += $laravelRuleName

    New-NetFirewallRule -Name $viteRuleName -DisplayName "$RulePrefix Vite $VitePort" -Direction Inbound -Protocol TCP -LocalAddress $lanIp -LocalPort $VitePort -RemoteAddress LocalSubnet -Action Allow -Profile Any | Out-Null
    $createdFirewallRuleNames += $viteRuleName

    $state = [ordered] @{
        version = 2
        stateKey = $StateKey
        lanIp = $lanIp
        wslIp = $wslIp
        isMirroredNetwork = $isMirroredNetwork
        mappings = @($createdMappings)
        firewallRuleNames = @($createdFirewallRuleNames)
    }

    $state | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $temporaryStatePath -Encoding UTF8
    Move-Item -LiteralPath $temporaryStatePath -Destination $StatePath -Force

    $laravelUrl = "http://{0}:{1}" -f $lanIp, $LaravelPort
    $viteUrl = "http://{0}:{1}" -f $lanIp, $VitePort
    $windowsLaravelUrl = if ($isMirroredNetwork) { "http://localhost:$LaravelPort" } else { $laravelUrl }
    $windowsViteUrl = if ($isMirroredNetwork) { "http://localhost:$VitePort" } else { $viteUrl }
    $laravelTcpOk = Test-NetConnection -ComputerName (if ($isMirroredNetwork) { 'localhost' } else { $lanIp }) -Port $LaravelPort -InformationLevel Quiet
    $viteTcpOk = Test-NetConnection -ComputerName (if ($isMirroredNetwork) { 'localhost' } else { $lanIp }) -Port $VitePort -InformationLevel Quiet
    $laravelHttpOk = Test-HttpEndpoint -Uri "$windowsLaravelUrl/up"
    $viteHttpOk = Test-HttpEndpoint -Uri "$windowsViteUrl/@vite/client"

    Write-Host ''
    Write-Host 'Compartilhamento LAN configurado.' -ForegroundColor Green
    if ($isMirroredNetwork) {
        Write-Host 'Rede espelhada do WSL detectada.' -ForegroundColor Yellow
        Write-Host "No Windows, abra Laravel: $windowsLaravelUrl"
        Write-Host "No Windows, abra Vite:    $windowsViteUrl"
        Write-Host "Em outros dispositivos, Laravel: $laravelUrl"
        Write-Host "Em outros dispositivos, Vite:    $viteUrl"
    } else {
        Write-Host "Laravel: $laravelUrl"
        Write-Host "Vite:    $viteUrl"
    }
    Write-Host "Estado:  $StatePath"
    Write-Host ''
    Write-Host "TCP Laravel: $(if ($laravelTcpOk) { 'OK' } else { 'FALHOU' })"
    Write-Host "TCP Vite:    $(if ($viteTcpOk) { 'OK' } else { 'FALHOU' })"
    Write-Host "HTTP Laravel: $(if ($laravelHttpOk) { 'OK' } else { 'aguardando o servidor WSL' })"
    Write-Host "HTTP Vite:    $(if ($viteHttpOk) { 'OK' } else { 'aguardando o servidor WSL' })"
    Write-Host ''
    Write-Host 'Para remover portproxy e firewall, gere e execute: php artisan lan:share:cleanup --script=lan-share-cleanup.ps1'
} catch {
    foreach ($mapping in @($createdMappings)) {
        Remove-PortProxyMapping -Mapping $mapping
    }

    foreach ($ruleName in @($createdFirewallRuleNames)) {
        Remove-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue
    }

    Remove-Item -LiteralPath $temporaryStatePath -Force -ErrorAction SilentlyContinue
    Write-Error $_.Exception.Message
    exit 1
}
