[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $PipeName,

    [int] $IdleTimeoutSeconds = 300,

    [switch] $AllowNonElevated
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$ProtocolVersion = 1
$RulePrefix = 'DougKusanagi-LaravelLanShare'
$StateDirectory = Join-Path $env:LOCALAPPDATA 'DougKusanagi\LaravelLanShare\agent\v1'
$StatePath = Join-Path $StateDirectory 'state.json'
$InfoPath = Join-Path $StateDirectory 'agent.json'
$LogPath = Join-Path $StateDirectory 'agent.log'

$script:Sessions = @{}
$script:Connections = New-Object System.Collections.ArrayList
$script:AcceptServer = $null
$script:AcceptTask = $null
$script:ShouldStop = $false
$script:LastActivity = Get-Date
$script:LastSweep = Get-Date

function Test-IsElevated {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)

    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not $AllowNonElevated -and -not (Test-IsElevated)) {
    throw 'O agente Windows precisa ser executado como Administrador.'
}

function Write-AgentLog {
    param([string] $Message)

    try {
        $timestamp = (Get-Date).ToUniversalTime().ToString('o')
        Add-Content -LiteralPath $LogPath -Value "[$timestamp] $Message" -Encoding UTF8
    } catch {
        # Logging must never prevent cleanup.
    }
}

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

    if ($null -eq $Mapping -or
        [string]::IsNullOrWhiteSpace([string] $Mapping.listenAddress) -or
        $null -eq $Mapping.listenPort) {
        return
    }

    Invoke-Netsh -Arguments @(
        'interface', 'portproxy', 'delete', 'v4tov4',
        "listenaddress=$($Mapping.listenAddress)",
        "listenport=$($Mapping.listenPort)"
    ) -IgnoreFailure
}

function Get-WslIp {
    param([string] $Distro)

    $wslArguments = @()

    if (-not [string]::IsNullOrWhiteSpace($Distro)) {
        $wslArguments = @('-d', $Distro)
    }

    $output = (& wsl.exe @wslArguments hostname -I 2>$null) -join ' '
    $address = @($output -split '\s+' | Where-Object {
        $_ -match '^\d{1,3}(\.\d{1,3}){3}$' -and
        $_ -notlike '127.*' -and
        $_ -notlike '169.254.*'
    }) | Select-Object -First 1

    if ([string]::IsNullOrWhiteSpace([string] $address)) {
        throw 'Não foi possível detectar o IP interno do WSL. Verifique se a distribuição está em execução.'
    }

    return [string] $address
}

function Get-LanIp {
    $address = Get-NetIPConfiguration -ErrorAction SilentlyContinue |
        Where-Object { $_.IPv4DefaultGateway -and $_.IPv4Address } |
        ForEach-Object { $_.IPv4Address.IPAddress } |
        Where-Object {
            $_ -and
            $_ -notlike '127.*' -and
            $_ -notlike '169.254.*'
        } |
        Select-Object -First 1

    if ([string]::IsNullOrWhiteSpace([string] $address)) {
        throw 'Não foi possível detectar um IP LAN ativo no Windows.'
    }

    return [string] $address
}

function Assert-IPv4 {
    param(
        [string] $Address,
        [string] $Name
    )

    if ([string]::IsNullOrWhiteSpace($Address) -or
        $Address -notmatch '^\d{1,3}(\.\d{1,3}){3}$') {
        throw "O endereço IPv4 de $Name não é válido."
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
        [int] $Start,
        [int] $Limit
    )

    if ($Start -lt 1 -or $Limit -lt 1) {
        return $false
    }

    $end = [Math]::Min(65535, $Start + $Limit - 1)

    return $Port -ge $Start -and $Port -le $end
}

function Remove-PortProxyOrphans {
    param(
        [string] $WslIp,
        [int] $LaravelPortStart,
        [int] $VitePortStart,
        [int] $PortSearchLimit
    )

    foreach ($mapping in @(Get-PortProxyMappings)) {
        $sameBackend = $mapping.connectAddress -eq $WslIp -and
            $mapping.connectPort -eq $mapping.listenPort
        $managedPort = (Test-PortInManagedRange -Port $mapping.listenPort -Start $LaravelPortStart -Limit $PortSearchLimit) -or
            (Test-PortInManagedRange -Port $mapping.listenPort -Start $VitePortStart -Limit $PortSearchLimit)

        if ($sameBackend -and $managedPort) {
            Remove-PortProxyMapping -Mapping $mapping
        }
    }
}

function Test-PortAvailable {
    param(
        [int] $Port,
        [string] $LanIp,
        [string] $WslIp,
        [object[]] $PortProxies
    )

    $matchingProxies = @($PortProxies | Where-Object { $_.listenPort -eq $Port })

    foreach ($mapping in $matchingProxies) {
        $sameWslBackend = $mapping.connectAddress -eq $WslIp -and
            $mapping.connectPort -eq $Port

        if (-not $sameWslBackend) {
            return $false
        }
    }

    $listeners = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)

    foreach ($listener in $listeners) {
        $isLoopback = $listener.LocalAddress -like '127.*' -or $listener.LocalAddress -eq '::1'
        $isRelevantAddress = $listener.LocalAddress -eq '0.0.0.0' -or
            $listener.LocalAddress -eq '::' -or
            $listener.LocalAddress -eq $LanIp
        $isManagedProxy = @($matchingProxies | Where-Object {
            $_.connectAddress -eq $WslIp -and $_.connectPort -eq $Port
        }).Count -gt 0

        if (-not $isLoopback -and $isRelevantAddress -and -not $isManagedProxy) {
            return $false
        }
    }

    return $true
}

function Find-Port {
    param(
        [int] $PreferredPort,
        [int] $SearchLimit,
        [string] $LanIp,
        [string] $WslIp,
        [int[]] $ReservedPorts,
        [object[]] $PortProxies
    )

    if ($PreferredPort -lt 1 -or $PreferredPort -gt 65535) {
        throw "A porta $PreferredPort não é válida."
    }

    for ($offset = 0; $offset -lt $SearchLimit; $offset++) {
        $port = $PreferredPort + $offset

        if ($port -gt 65535 -or $ReservedPorts -contains $port) {
            continue
        }

        if (Test-PortAvailable -Port $port -LanIp $LanIp -WslIp $WslIp -PortProxies $PortProxies) {
            return $port
        }
    }

    throw "Não foi encontrada uma porta livre entre $PreferredPort e $([Math]::Min(65535, $PreferredPort + $SearchLimit - 1))."
}

function Get-StateRecords {
    if (-not (Test-Path -LiteralPath $StatePath)) {
        return @()
    }

    try {
        $state = Get-Content -LiteralPath $StatePath -Raw | ConvertFrom-Json
        return @($state.sessions)
    } catch {
        Write-AgentLog "Não foi possível ler o estado anterior: $($_.Exception.Message)"
        return @()
    }
}

function Get-LegacyStatePaths {
    param([string] $StateKey)

    $legacyDirectory = Join-Path $env:LOCALAPPDATA 'DougKusanagi\LaravelLanShare'

    return @(
        (Join-Path $legacyDirectory ("state-{0}.json" -f $StateKey)),
        (Join-Path $legacyDirectory 'state.json')
    ) | Select-Object -Unique
}

function Remove-LegacyStateResources {
    param(
        [string] $StateKey,
        [string] $RulePrefix
    )

    foreach ($path in @(Get-LegacyStatePaths -StateKey $StateKey)) {
        if (-not (Test-Path -LiteralPath $path)) {
            continue
        }

        try {
            $state = Get-Content -LiteralPath $path -Raw | ConvertFrom-Json

            foreach ($mapping in @($state.mappings)) {
                Remove-PortProxyMapping -Mapping $mapping
            }

            foreach ($ruleName in @($state.firewallRuleNames)) {
                Remove-NetFirewallRule -Name ([string] $ruleName) -ErrorAction SilentlyContinue | Out-Null
            }
        } catch {
            Write-AgentLog "Não foi possível reconciliar o estado legado ${path}: $($_.Exception.Message)"
        }

        Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
    }

    Remove-NetFirewallRule -Name "$RulePrefix-Laravel" -ErrorAction SilentlyContinue | Out-Null
    Remove-NetFirewallRule -Name "$RulePrefix-Vite" -ErrorAction SilentlyContinue | Out-Null
}

function Save-State {
    New-Item -ItemType Directory -Path $StateDirectory -Force | Out-Null
    $temporaryPath = "$StatePath.$PID.tmp"
    $payload = [ordered] @{
        version = 1
        sessions = @($script:Sessions.Values)
    }

    $payload | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $temporaryPath -Encoding UTF8
    Move-Item -LiteralPath $temporaryPath -Destination $StatePath -Force | Out-Null
}

function Remove-SessionResources {
    param([object] $Session)

    foreach ($mapping in @($Session.mappings)) {
        Remove-PortProxyMapping -Mapping $mapping
    }

    foreach ($ruleName in @($Session.firewallRuleNames)) {
        Remove-NetFirewallRule -Name ([string] $ruleName) -ErrorAction SilentlyContinue | Out-Null
    }
}

function Remove-Session {
    param([string] $SessionId)

    if (-not $script:Sessions.ContainsKey($SessionId)) {
        return $false
    }

    $session = $script:Sessions[$SessionId]
    Remove-SessionResources -Session $session
    [void] $script:Sessions.Remove($SessionId)
    Save-State

    return $true
}

function New-Response {
    param([hashtable] $Data = @{})

    $response = [ordered] @{
        protocol = $ProtocolVersion
        ok = $true
    }

    foreach ($key in $Data.Keys) {
        $response[$key] = $Data[$key]
    }

    return $response
}

function New-ErrorResponse {
    param(
        [string] $Message,
        [string] $Code = 'agent_error'
    )

    return [ordered] @{
        protocol = $ProtocolVersion
        ok = $false
        code = $Code
        error = $Message
    }
}

function Get-SessionSummaries {
    return @($script:Sessions.Values | ForEach-Object {
        [ordered] @{
            sessionId = $_.sessionId
            projectId = $_.projectId
            stateKey = $_.stateKey
            laravelPort = $_.laravelPort
            vitePort = $_.vitePort
            lanIp = $_.lanIp
            wslIp = $_.wslIp
            lastHeartbeat = $_.lastHeartbeat
        }
    })
}

function Get-SafeName {
    param([string] $Value)

    $safe = $Value -replace '[^A-Za-z0-9_.-]', '-'

    if ([string]::IsNullOrWhiteSpace($safe)) {
        return 'default'
    }

    return $safe.Substring(0, [Math]::Min(80, $safe.Length))
}

function Invoke-Prepare {
    param([object] $Request)

    $projectId = [string] $Request.projectId
    $sessionId = [string] $Request.sessionId
    $stateKey = [string] $Request.stateKey
    $wslDistro = [string] $Request.wslDistro
    $lanHost = [string] $Request.lanHost
    $rulePrefix = [string] $Request.firewallRulePrefix
    $laravelPortStart = [int] $Request.laravelPort
    $vitePortStart = [int] $Request.vitePort
    $portSearchLimit = [int] $Request.portSearchLimit

    if ($projectId -notmatch '^[A-Za-z0-9_.-]{1,128}$' -or
        $sessionId -notmatch '^[A-Za-z0-9_.-]{1,128}$' -or
        $stateKey -notmatch '^[A-Za-z0-9_.-]{1,128}$') {
        throw 'O identificador do projeto ou da sessão não é válido.'
    }

    if ($portSearchLimit -lt 1 -or $portSearchLimit -gt 1000) {
        throw 'O limite de busca de portas não é válido.'
    }

    $wslIp = Get-WslIp -Distro $wslDistro
    $lanIp = if ([string]::IsNullOrWhiteSpace($lanHost)) { Get-LanIp } else { $lanHost }
    Assert-IPv4 -Address $wslIp -Name 'WSL'
    Assert-IPv4 -Address $lanIp -Name 'LAN'

    Remove-LegacyStateResources -StateKey $stateKey -RulePrefix $rulePrefix

    foreach ($oldSessionId in @($script:Sessions.Keys)) {
        if ([string] $script:Sessions[$oldSessionId].projectId -eq $projectId) {
            [void] (Remove-Session -SessionId ([string] $oldSessionId))
        }
    }

    Remove-PortProxyOrphans -WslIp $wslIp `
        -LaravelPortStart $laravelPortStart `
        -VitePortStart $vitePortStart `
        -PortSearchLimit $portSearchLimit

    $portProxies = @(Get-PortProxyMappings)
    $laravelPort = Find-Port -PreferredPort $laravelPortStart -SearchLimit $portSearchLimit `
        -LanIp $lanIp -WslIp $wslIp -ReservedPorts @() -PortProxies $portProxies
    $vitePort = Find-Port -PreferredPort $vitePortStart -SearchLimit $portSearchLimit `
        -LanIp $lanIp -WslIp $wslIp -ReservedPorts @($laravelPort) -PortProxies $portProxies

    $createdMappings = @()
    $createdFirewallRuleNames = @()
    $safePrefix = Get-SafeName -Value $rulePrefix
    $safeStateKey = Get-SafeName -Value $stateKey

    try {
        foreach ($port in @($laravelPort, $vitePort)) {
            Invoke-Netsh -Arguments @(
                'interface', 'portproxy', 'add', 'v4tov4',
                "listenaddress=$lanIp", "listenport=$port",
                "connectaddress=$wslIp", "connectport=$port"
            )

            $createdMappings += [ordered] @{
                listenAddress = $lanIp
                listenPort = $port
                connectAddress = $wslIp
                connectPort = $port
            }
        }

        $laravelRuleName = "$safePrefix-$safeStateKey-Laravel"
        $viteRuleName = "$safePrefix-$safeStateKey-Vite"

        Remove-NetFirewallRule -Name $laravelRuleName -ErrorAction SilentlyContinue | Out-Null
        Remove-NetFirewallRule -Name $viteRuleName -ErrorAction SilentlyContinue | Out-Null

        New-NetFirewallRule -Name $laravelRuleName `
            -DisplayName "$safePrefix Laravel $laravelPort" `
            -Direction Inbound -Protocol TCP -LocalAddress $lanIp `
            -LocalPort $laravelPort -RemoteAddress LocalSubnet -Action Allow -Profile Any | Out-Null
        $createdFirewallRuleNames += $laravelRuleName

        New-NetFirewallRule -Name $viteRuleName `
            -DisplayName "$safePrefix Vite $vitePort" `
            -Direction Inbound -Protocol TCP -LocalAddress $lanIp `
            -LocalPort $vitePort -RemoteAddress LocalSubnet -Action Allow -Profile Any | Out-Null
        $createdFirewallRuleNames += $viteRuleName

        $session = [ordered] @{
            version = 1
            sessionId = $sessionId
            projectId = $projectId
            stateKey = $stateKey
            lanIp = $lanIp
            wslIp = $wslIp
            laravelPort = $laravelPort
            vitePort = $vitePort
            mappings = @($createdMappings)
            firewallRuleNames = @($createdFirewallRuleNames)
            leaseSeconds = [Math]::Max(5, [int] $Request.leaseSeconds)
            lastHeartbeat = (Get-Date).ToUniversalTime().ToString('o')
        }

        $script:Sessions[$sessionId] = [pscustomobject] $session
        Save-State

        return New-Response -Data @{
            sessionId = $sessionId
            projectId = $projectId
            laravelPort = $laravelPort
            vitePort = $vitePort
            lanIp = $lanIp
            wslIp = $wslIp
        }
    } catch {
        foreach ($mapping in @($createdMappings)) {
            Remove-PortProxyMapping -Mapping $mapping
        }

        foreach ($ruleName in @($createdFirewallRuleNames)) {
            Remove-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue | Out-Null
        }

        throw
    }
}

function Invoke-Heartbeat {
    param([object] $Request)

    $sessionId = [string] $Request.sessionId

    if (-not $script:Sessions.ContainsKey($sessionId)) {
        throw 'A sessão do compartilhamento não existe mais.'
    }

    $session = $script:Sessions[$sessionId]
    $session.lastHeartbeat = (Get-Date).ToUniversalTime().ToString('o')
    Save-State
    return New-Response -Data @{ sessionId = $sessionId }
}

function Invoke-Stop {
    param([object] $Request)

    $sessionId = [string] $Request.sessionId
    $projectId = [string] $Request.projectId
    $stopped = $false

    if (-not [string]::IsNullOrWhiteSpace($sessionId)) {
        $stopped = Remove-Session -SessionId $sessionId
    } elseif (-not [string]::IsNullOrWhiteSpace($projectId)) {
        foreach ($oldSessionId in @($script:Sessions.Keys)) {
            if ([string] $script:Sessions[$oldSessionId].projectId -eq $projectId) {
                $stopped = (Remove-Session -SessionId ([string] $oldSessionId)) -or $stopped
            }
        }
    }

    return New-Response -Data @{ stopped = $stopped }
}

function Invoke-Request {
    param([object] $Request)

    $script:LastActivity = Get-Date
    $operation = [string] $Request.operation

    switch ($operation) {
        'hello' {
            return New-Response -Data @{ agentVersion = 'powershell-v1'; pid = $PID }
        }
        'prepare' {
            return Invoke-Prepare -Request $Request
        }
        'heartbeat' {
            return Invoke-Heartbeat -Request $Request
        }
        'stop' {
            return Invoke-Stop -Request $Request
        }
        'status' {
            return New-Response -Data @{ sessions = @(Get-SessionSummaries) }
        }
        'reconcile' {
            Invoke-LeaseSweep -Force
            return New-Response -Data @{ sessions = @(Get-SessionSummaries) }
        }
        'shutdown' {
            foreach ($sessionId in @($script:Sessions.Keys)) {
                [void] (Remove-Session -SessionId ([string] $sessionId))
            }

            $script:ShouldStop = $true
            return New-Response -Data @{ stopped = $true }
        }
        default {
            throw "Operação '$operation' não é suportada pelo agente."
        }
    }
}

function Invoke-LeaseSweep {
    param([switch] $Force)

    $now = Get-Date

    if (-not $Force -and ($now - $script:LastSweep).TotalSeconds -lt 1) {
        return
    }

    $script:LastSweep = $now
    $changed = $false

    foreach ($sessionId in @($script:Sessions.Keys)) {
        $session = $script:Sessions[$sessionId]
        $lastHeartbeat = [DateTime]::MinValue

        try {
            $lastHeartbeat = [DateTime]::Parse([string] $session.lastHeartbeat).ToUniversalTime()
        } catch {
            $lastHeartbeat = [DateTime]::MinValue
        }

        $leaseSeconds = [Math]::Max(5, [int] $session.leaseSeconds)

        if (($now.ToUniversalTime() - $lastHeartbeat).TotalSeconds -gt $leaseSeconds) {
            Write-AgentLog "Lease expirada para a sessão $($session.sessionId)."
            Remove-SessionResources -Session $session
            [void] $script:Sessions.Remove([string] $sessionId)
            $changed = $true
        }
    }

    if ($changed) {
        Save-State
    }
}

function Start-AcceptTask {
    # O agente roda elevado, mas o cliente permanece no processo do WSL sem
    # elevação. A ACL explícita permite que o mesmo usuário atravesse o UAC e
    # converse com o agente pelo pipe nomeado.
    $pipeSecurity = New-Object System.IO.Pipes.PipeSecurity
    $currentUserSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $pipeAccessRule = New-Object System.IO.Pipes.PipeAccessRule(
        $currentUserSid,
        ([System.IO.Pipes.PipeAccessRights]::ReadWrite -bor
            [System.IO.Pipes.PipeAccessRights]::CreateNewInstance),
        [System.Security.AccessControl.AccessControlType]::Allow
    )
    $pipeSecurity.AddAccessRule($pipeAccessRule)

    $script:AcceptServer = [System.IO.Pipes.NamedPipeServerStream]::new(
        $PipeName,
        [System.IO.Pipes.PipeDirection]::InOut,
        16,
        [System.IO.Pipes.PipeTransmissionMode]::Byte,
        [System.IO.Pipes.PipeOptions]::Asynchronous,
        4096,
        4096,
        $pipeSecurity
    )
    $script:AcceptTask = $script:AcceptServer.WaitForConnectionAsync()
}

function Close-Connection {
    param([object] $Connection)

    if ($Connection.Closed) {
        return
    }

    $Connection.Closed = $true

    try { $Connection.Reader.Dispose() } catch {}
    try { $Connection.Writer.Dispose() } catch {}
    try { $Connection.Pipe.Dispose() } catch {}
}

function Start-ConnectionRead {
    param([object] $Connection)

    $Connection.ReadTask = $Connection.Reader.ReadLineAsync()
}

function Add-ConnectedConnection {
    param([object] $Pipe)

    $pipe = $Pipe
    $connection = [pscustomobject] @{
        Pipe = $pipe
        Reader = New-Object System.IO.StreamReader($pipe)
        Writer = New-Object System.IO.StreamWriter($pipe)
        ReadTask = $null
        Closed = $false
        CloseAfterResponse = $false
    }
    $connection.Writer.AutoFlush = $true
    Start-ConnectionRead -Connection $connection
    [void] $script:Connections.Add($connection)
    $script:LastActivity = Get-Date
    Start-AcceptTask
}

function Process-Connection {
    param([object] $Connection)

    if ($Connection.Closed -or -not $Connection.ReadTask.Wait(0)) {
        return
    }

    try {
        $line = $Connection.ReadTask.GetAwaiter().GetResult()

        if ($null -eq $line) {
            Close-Connection -Connection $Connection
            return
        }

        if ([string]::IsNullOrWhiteSpace($line)) {
            Start-ConnectionRead -Connection $Connection
            return
        }

        try {
            $request = $line | ConvertFrom-Json
            $response = Invoke-Request -Request $request
        } catch {
            $response = New-ErrorResponse -Message $_.Exception.Message
            Write-AgentLog "Erro ao processar requisição: $($_.Exception.Message)"
        }

        $Connection.Writer.WriteLine(($response | ConvertTo-Json -Compress -Depth 10))

        if ($script:ShouldStop) {
            $Connection.CloseAfterResponse = $true
        }

        if ($Connection.CloseAfterResponse) {
            Close-Connection -Connection $Connection
        } else {
            Start-ConnectionRead -Connection $Connection
        }
    } catch {
        Write-AgentLog "Conexão encerrada com erro: $($_.Exception.Message)"
        Close-Connection -Connection $Connection
    }
}

function Remove-ClosedConnections {
    foreach ($connection in @($script:Connections)) {
        if ($connection.Closed) {
            [void] $script:Connections.Remove($connection)
        }
    }
}

function Stop-AllSessions {
    foreach ($sessionId in @($script:Sessions.Keys)) {
        try {
            Remove-SessionResources -Session $script:Sessions[$sessionId]
        } catch {
            Write-AgentLog "Falha ao limpar a sessão ${sessionId}: $($_.Exception.Message)"
        }

        [void] $script:Sessions.Remove([string] $sessionId)
    }

    try { Save-State } catch {}
}

New-Item -ItemType Directory -Path $StateDirectory -Force | Out-Null
$ownsPipe = $false
$ownsAgentMutex = $false
$agentMutex = $null
$exitCode = 0

try {
    # A posse exclusiva do mutex precisa ser confirmada antes de tocar no estado. Assim,
    # uma segunda instância acidental não remove recursos do agente ativo.
    $mutexCreated = $false
    $agentMutex = New-Object System.Threading.Mutex(
        $true,
        ("Local\{0}.Agent" -f $PipeName),
        [ref] $mutexCreated
    )

    if (-not $mutexCreated) {
        throw 'Outra instância do agente Windows já está ativa.'
    }

    $ownsAgentMutex = $true
    Start-AcceptTask
    $ownsPipe = $true

    # O estado persistido é considerado órfão quando um agente novo assume o pipe.
    # As sessões ativas serão recriadas pelo cliente após a conexão.
    foreach ($record in @(Get-StateRecords)) {
        try {
            Remove-SessionResources -Session $record
        } catch {
            Write-AgentLog "Falha ao reconciliar uma sessão antiga: $($_.Exception.Message)"
        }
    }
    $script:Sessions = @{}
    Save-State

    $agentInfo = [ordered] @{
        protocol = $ProtocolVersion
        version = 'powershell-v1'
        pid = $PID
        pipeName = $PipeName
        startedAt = (Get-Date).ToUniversalTime().ToString('o')
    }
    $agentInfo | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $InfoPath -Encoding UTF8
    Write-AgentLog "Agente iniciado no pipe $PipeName."

    while (-not $script:ShouldStop) {
        if ($script:AcceptTask.IsCompleted) {
            try {
                $script:AcceptTask.GetAwaiter().GetResult()
                Add-ConnectedConnection -Pipe $script:AcceptServer
            } catch {
                Write-AgentLog "Falha ao aceitar conexão: $($_.Exception.Message)"
                Start-Sleep -Milliseconds 200
                Start-AcceptTask
            }
        }

        foreach ($connection in @($script:Connections)) {
            Process-Connection -Connection $connection
        }
        Remove-ClosedConnections
        Invoke-LeaseSweep

        $hasSessions = $script:Sessions.Count -gt 0
        $hasConnections = @($script:Connections | Where-Object { -not $_.Closed }).Count -gt 0

        if (-not $hasSessions -and -not $hasConnections -and
            ((Get-Date) - $script:LastActivity).TotalSeconds -ge [Math]::Max(30, $IdleTimeoutSeconds)) {
            break
        }

        Start-Sleep -Milliseconds 100
    }
} catch {
    $exitCode = 1
    Write-AgentLog "Erro fatal do agente: $($_.Exception.Message)"
} finally {
    if ($ownsPipe) {
        Stop-AllSessions

        foreach ($connection in @($script:Connections)) {
            Close-Connection -Connection $connection
        }

        try { $script:AcceptServer.Dispose() } catch {}
        Remove-Item -LiteralPath $InfoPath -Force -ErrorAction SilentlyContinue
        Write-AgentLog 'Agente encerrado.'
    }

    if ($null -ne $agentMutex) {
        if ($ownsAgentMutex) {
            try { $agentMutex.ReleaseMutex() } catch {}
        }

        try { $agentMutex.Dispose() } catch {}
    }
}

exit $exitCode
