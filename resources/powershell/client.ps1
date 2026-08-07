[CmdletBinding()]
param(
    [int] $IdleTimeoutSeconds = 300,

    [string] $ExpectedVersion = ''
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$ProtocolVersion = 1
$AgentRoot = Join-Path $env:LOCALAPPDATA 'DougKusanagi\LaravelLanShare\agent\v1'
$AgentPath = Join-Path $AgentRoot 'agent.ps1'
$VersionPath = Join-Path $AgentRoot 'version.txt'

$utf8 = New-Object System.Text.UTF8Encoding($false)
[Console]::InputEncoding = $utf8
[Console]::OutputEncoding = $utf8
$OutputEncoding = $utf8

if (-not [string]::IsNullOrWhiteSpace($ExpectedVersion)) {
    if (-not (Test-Path -LiteralPath $VersionPath)) {
        throw '[lan-share:install-required] O agente Windows não está instalado. Execute php artisan lan:install.'
    }

    $installedVersion = (Get-Content -LiteralPath $VersionPath -Raw).Trim()

    if ($installedVersion -ne $ExpectedVersion) {
        throw '[lan-share:install-required] O agente Windows está desatualizado. Execute php artisan lan:install.'
    }
}

function Get-PipeName {
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $safeSid = $sid -replace '[^A-Za-z0-9]', '_'

    return "DougKusanagi.LaravelLanShare.$safeSid"
}

function Quote-Argument {
    param([string] $Value)

    return '"' + $Value.Replace('"', '\"') + '"'
}

function Test-Elevated {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)

    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function New-PipeClient {
    $pipe = New-Object System.IO.Pipes.NamedPipeClientStream(
        '.',
        (Get-PipeName),
        [System.IO.Pipes.PipeDirection]::InOut,
        [System.IO.Pipes.PipeOptions]::None
    )

    $pipe.Connect(1000)
    $reader = New-Object System.IO.StreamReader($pipe)
    $writer = New-Object System.IO.StreamWriter($pipe)
    $writer.AutoFlush = $true

    return [pscustomobject] @{
        Pipe = $pipe
        Reader = $reader
        Writer = $writer
    }
}

function Start-Agent {
    if (-not (Test-Path -LiteralPath $AgentPath)) {
        throw 'O agente Windows não está instalado no diretório local.'
    }

    $pipeName = Get-PipeName
    $arguments = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' +
        (Quote-Argument -Value $AgentPath) +
        ' -PipeName ' +
        (Quote-Argument -Value $pipeName) +
        ' -IdleTimeoutSeconds ' +
        ([Math]::Max(30, $IdleTimeoutSeconds))

    try {
        if (Test-Elevated) {
            Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden -ArgumentList $arguments | Out-Null
        } else {
            Start-Process -FilePath 'powershell.exe' -Verb RunAs -WindowStyle Hidden -ArgumentList $arguments | Out-Null
        }
    } catch {
        throw 'O agente Windows precisa de privilégios administrativos. A confirmação do UAC foi cancelada ou falhou.'
    }
}

function Connect-Agent {
    try {
        return New-PipeClient
    } catch {
        Start-Agent

        for ($attempt = 0; $attempt -lt 60; $attempt++) {
            Start-Sleep -Milliseconds 250

            try {
                return New-PipeClient
            } catch {
                # O processo elevado ainda pode estar aguardando o início.
            }
        }

        throw 'O agente Windows não ficou disponível após a elevação. Execute lan:share:doctor para obter detalhes.'
    }
}

function Close-PipeClient {
    param([object] $Client)

    if ($null -eq $Client) {
        return
    }

    try { $Client.Reader.Dispose() } catch {}
    try { $Client.Writer.Dispose() } catch {}
    try { $Client.Pipe.Dispose() } catch {}
}

function New-ErrorResponse {
    param(
        [string] $Message,
        [string] $Code = 'client_error'
    )

    return [ordered] @{
        protocol = $ProtocolVersion
        ok = $false
        code = $Code
        error = $Message
    }
}

function Invoke-AgentRequest {
    param(
        [object] $Request,
        [object] $Client
    )

    $json = $Request | ConvertTo-Json -Compress -Depth 10

    for ($attempt = 0; $attempt -lt 2; $attempt++) {
        if ($null -eq $Client) {
            $Client = Connect-Agent
        }

        try {
            $Client.Writer.WriteLine($json)
            $responseLine = $Client.Reader.ReadLine()

            if ($null -eq $responseLine) {
                throw 'O agente encerrou a conexão.'
            }

            return [pscustomobject] @{
                Response = ($responseLine | ConvertFrom-Json)
                Client = $Client
            }
        } catch {
            Close-PipeClient -Client $Client
            $Client = $null

            if ($attempt -eq 1) {
                throw
            }
        }
    }
}

$client = $null

try {
    while ($true) {
        $line = [Console]::In.ReadLine()

        if ($null -eq $line) {
            break
        }

        if ([string]::IsNullOrWhiteSpace($line)) {
            continue
        }

        try {
            $request = $line | ConvertFrom-Json
            $result = Invoke-AgentRequest -Request $request -Client $client
            $client = $result.Client
            $response = $result.Response
        } catch {
            $response = New-ErrorResponse -Message $_.Exception.Message
        }

        [Console]::Out.WriteLine(($response | ConvertTo-Json -Compress -Depth 10))
        [Console]::Out.Flush()
    }
} finally {
    Close-PipeClient -Client $client
}
