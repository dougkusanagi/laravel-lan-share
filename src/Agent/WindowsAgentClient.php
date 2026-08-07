<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Agent;

use DougKusanagi\LaravelLanShare\PowerShell\PowerShellCommandRenderer;
use DougKusanagi\LaravelLanShare\Support\LanSharePlan;
use Illuminate\Support\Facades\Process;
use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;
use Throwable;

final class WindowsAgentClient
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private bool $clientEnforcesVersion = true;

    public function __construct(
        private readonly PowerShellCommandRenderer $commandRenderer,
        private readonly string $resourceDirectory = __DIR__.'/../../resources/powershell',
    ) {}

    public function isAvailable(): bool
    {
        try {
            $result = Process::timeout(5)->run([
                'powershell.exe',
                '-NoProfile',
                '-NonInteractive',
                '-Command',
                'Write-Output lan-share-agent-ready',
            ]);

            return $result->successful() && str_contains($result->output(), 'lan-share-agent-ready');
        } catch (Throwable) {
            return false;
        }
    }

    public function prepare(LanSharePlan $plan): AgentShareSession
    {
        $sessionId = bin2hex(random_bytes(16));
        $projectId = $plan->stateKey;
        $response = $this->request([
            'operation' => 'prepare',
            'projectId' => $projectId,
            'sessionId' => $sessionId,
            'stateKey' => $plan->stateKey,
            'lanHost' => $plan->host ?? '',
            'wslDistro' => $plan->wslDistro ?? '',
            'firewallRulePrefix' => $plan->firewallRulePrefix,
            'laravelPort' => $plan->laravelPort,
            'vitePort' => $plan->vitePort,
            'portSearchLimit' => $plan->portSearchLimit,
            'leaseSeconds' => max(5, (int) config('lan-share.agent.lease_seconds', 12)),
        ]);

        $laravelPort = $this->responsePort($response, 'laravelPort');
        $vitePort = $this->responsePort($response, 'vitePort');
        $lanIp = $this->responseString($response, 'lanIp');
        $wslIp = $this->responseString($response, 'wslIp');

        return new AgentShareSession(
            sessionId: $this->responseString($response, 'sessionId'),
            projectId: $projectId,
            plan: $plan->withRuntime($laravelPort, $vitePort, $lanIp),
            lanIp: $lanIp,
            wslIp: $wslIp,
        );
    }

    public function heartbeat(AgentShareSession $session): void
    {
        $this->request([
            'operation' => 'heartbeat',
            'sessionId' => $session->sessionId,
        ]);
    }

    public function stop(AgentShareSession $session): void
    {
        try {
            $this->request([
                'operation' => 'stop',
                'sessionId' => $session->sessionId,
            ]);
        } finally {
            $this->close();
        }
    }

    public function stopProject(string $projectId): bool
    {
        try {
            $response = $this->request([
                'operation' => 'stop',
                'projectId' => $projectId,
            ]);

            return ($response['stopped'] ?? false) === true;
        } finally {
            $this->close();
        }
    }

    public function isRunning(): bool
    {
        $probe = <<<'POWERSHELL'
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value -replace '[^A-Za-z0-9]', '_'
$pipeName = "DougKusanagi.LaravelLanShare.$sid"
$pipe = New-Object System.IO.Pipes.NamedPipeClientStream('.', $pipeName, [System.IO.Pipes.PipeDirection]::InOut, [System.IO.Pipes.PipeOptions]::None)

try {
    $pipe.Connect(250)
    Write-Output 'lan-share-agent-running'
} catch {
    exit 1
} finally {
    $pipe.Dispose()
}
POWERSHELL;

        try {
            $result = Process::timeout(2)->run([
                'powershell.exe',
                '-NoProfile',
                '-NonInteractive',
                '-Command',
                $probe,
            ]);

            return $result->successful() && str_contains($result->output(), 'lan-share-agent-running');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function status(bool $startIfMissing = true): array
    {
        if (! $startIfMissing && ! $this->isRunning()) {
            return [
                'protocol' => 1,
                'ok' => true,
                'running' => false,
                'sessions' => [],
            ];
        }

        $response = $this->request(['operation' => 'status']);
        $response['running'] = true;

        return $response;
    }

    public function shutdown(): bool
    {
        if (! $this->isRunning()) {
            return false;
        }

        try {
            $response = $this->request(['operation' => 'shutdown']);

            return ($response['stopped'] ?? false) === true;
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];

        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }

        $this->process = null;
        $this->clientEnforcesVersion = true;
    }

    /** @return array{ok: true, version: string, path: string, updated: bool, agent_stopped: bool} */
    public function install(): array
    {
        $wasRunning = $this->isRunning();
        $installation = $this->installRuntime();
        $agentStopped = false;

        if ($wasRunning) {
            $this->close();

            try {
                $this->request(['operation' => 'shutdown']);
                $agentStopped = true;
            } finally {
                $this->close();
            }
        }

        return $installation + ['agent_stopped' => $agentStopped];
    }

    /** @return array{ok: true, path: string, removed: bool, agent_stopped: bool} */
    public function uninstall(): array
    {
        $agentStopped = false;

        try {
            // Starting the installed agent here is intentional: it reloads any
            // persisted sessions and removes their firewall/portproxy resources.
            $response = $this->request(['operation' => 'shutdown'], false);
            $agentStopped = ($response['stopped'] ?? false) === true;
        } catch (Throwable $exception) {
            if (! str_contains($exception->getMessage(), 'não está instalado') &&
                ! str_contains($exception->getMessage(), 'n�o est� instalado')) {
                throw $exception;
            }
        } finally {
            $this->close();
        }

        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$base = [IO.Path]::GetFullPath([IO.Path]::Combine($env:LOCALAPPDATA, 'DougKusanagi', 'LaravelLanShare', 'agent'))
$root = [IO.Path]::GetFullPath([IO.Path]::Combine($base, 'v1'))
$expected = [IO.Path]::Combine($base, 'v1')

if ($root -ne $expected -or [IO.Path]::GetFileName($root) -ne 'v1') {
    throw 'O diretório do agente Windows não é seguro para remoção.'
}

$removed = Test-Path -LiteralPath $root

if ($removed) {
    Remove-Item -LiteralPath $root -Recurse -Force
}

Write-Output ("path:{0}" -f $root)
Write-Output ("removed:{0}" -f $removed)
POWERSHELL;

        $result = $this->runPowerShellScript(
            $script,
            'DougKusanagi-LaravelLanShare-AgentUninstall.ps1',
            'Não foi possível desinstalar o agente Windows.',
        );

        return [
            'ok' => true,
            'path' => $this->outputValue($result->output(), 'path'),
            'removed' => strtolower($this->outputValue($result->output(), 'removed')) === 'true',
            'agent_stopped' => $agentStopped,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(array $payload, bool $enforceVersion = true): array
    {
        $this->ensureClientProcess($enforceVersion);

        $encodedPayload = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        if (! is_resource($this->pipes[0] ?? null) ||
            ! is_resource($this->pipes[1] ?? null)) {
            throw new RuntimeException('O cliente do agente Windows não está disponível.');
        }

        $requestLine = $encodedPayload.PHP_EOL;

        while ($requestLine !== '') {
            $written = fwrite($this->pipes[0], $requestLine);

            if ($written === false || $written === 0) {
                $error = $this->clientErrorOutput();
                $this->close();

                throw $this->clientFailure($error, 'Não foi possível enviar a requisição ao agente Windows.');
            }

            $requestLine = substr($requestLine, $written);
        }

        $line = fgets($this->pipes[1]);

        if ($line === false) {
            $error = $this->clientErrorOutput();
            $this->close();

            throw $this->clientFailure($error, 'O agente Windows encerrou a comunicação inesperadamente.');
        }

        $response = json_decode(trim($line), true);

        if (! is_array($response)) {
            throw new RuntimeException('O agente Windows retornou uma resposta inválida.');
        }

        if (($response['protocol'] ?? null) !== 1) {
            throw new RuntimeException('A versão do protocolo do agente Windows é incompatível.');
        }

        if (($response['ok'] ?? false) !== true) {
            $message = is_string($response['error'] ?? null)
                ? $response['error']
                : 'O agente Windows recusou a operação.';
            $code = is_string($response['code'] ?? null) ? $response['code'] : 'agent_error';

            throw new RuntimeException("[$code] $message");
        }

        return $response;
    }

    /** @return array{ok: true, version: string, path: string, updated: bool} */
    private function installRuntime(): array
    {
        $agent = $this->readResource('agent.ps1');
        $client = $this->readResource('client.ps1');
        $version = $this->runtimeVersion($agent, $client);
        $agentPayload = $this->compressBase64($agent);
        $clientPayload = $this->compressBase64($client);

        $bootstrap = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$root = [IO.Path]::Combine($env:LOCALAPPDATA, 'DougKusanagi', 'LaravelLanShare', 'agent', 'v1')
$markerPath = [IO.Path]::Combine($root, 'version.txt')
$version = '{{ version }}'
$files = @{
    'agent.ps1' = '{{ agent_payload }}'
    'client.ps1' = '{{ client_payload }}'
}

function Expand-CompressedBase64 {
    param([string] $Value)

    $inputBytes = [Convert]::FromBase64String($Value)
    $inputStream = New-Object IO.MemoryStream(, $inputBytes)
    $gzipStream = New-Object IO.Compression.GzipStream($inputStream, [IO.Compression.CompressionMode]::Decompress)
    $outputStream = New-Object IO.MemoryStream

    try {
        $gzipStream.CopyTo($outputStream)
        return $outputStream.ToArray()
    } finally {
        $gzipStream.Dispose()
        $inputStream.Dispose()
        $outputStream.Dispose()
    }
}

New-Item -ItemType Directory -Path $root -Force | Out-Null
$installedVersion = if (Test-Path -LiteralPath $markerPath) { (Get-Content -LiteralPath $markerPath -Raw).Trim() } else { '' }
$updated = $installedVersion -ne $version

if ($updated) {
    foreach ($entry in $files.GetEnumerator()) {
        [IO.File]::WriteAllBytes([IO.Path]::Combine($root, $entry.Key), (Expand-CompressedBase64 -Value $entry.Value))
    }

    [IO.File]::WriteAllText($markerPath, $version, [Text.Encoding]::ASCII)
}

Write-Output 'lan-share-agent-installed'
Write-Output ("path:{0}" -f $root)
Write-Output ("updated:{0}" -f $updated)
POWERSHELL;

        $bootstrap = strtr($bootstrap, [
            '{{ version }}' => $version,
            '{{ agent_payload }}' => $agentPayload,
            '{{ client_payload }}' => $clientPayload,
        ]);
        $command = $this->commandRenderer->render($bootstrap, 'DougKusanagi-LaravelLanShare-AgentBootstrap.ps1');

        $result = $this->runPowerShellCommand($command, 20);

        if (! $result->successful() || ! str_contains($result->output(), 'lan-share-agent-installed')) {
            $details = trim($result->errorOutput() ?: $result->output());

            throw new AgentUnavailableException(
                $details !== ''
                    ? "Não foi possível instalar o agente Windows: $details"
                    : 'Não foi possível instalar o agente Windows.',
            );
        }

        return [
            'ok' => true,
            'version' => $version,
            'path' => $this->outputValue($result->output(), 'path'),
            'updated' => strtolower($this->outputValue($result->output(), 'updated')) === 'true',
        ];
    }

    private function ensureClientProcess(bool $enforceVersion = true): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);

            if ($status['running'] === true && $this->clientEnforcesVersion === $enforceVersion) {
                return;
            }

            $this->close();
        }

        $idleTimeout = max(30, (int) config('lan-share.agent.idle_timeout', 300));
        $versionArgument = $enforceVersion
            ? " -ExpectedVersion '{$this->runtimeVersion()}'"
            : '';
        $clientCommand = <<<'POWERSHELL'
$utf8 = New-Object Text.UTF8Encoding($false)
[Console]::InputEncoding = $utf8
[Console]::OutputEncoding = $utf8
$OutputEncoding = $utf8
$clientPath = [IO.Path]::Combine($env:LOCALAPPDATA, 'DougKusanagi', 'LaravelLanShare', 'agent', 'v1', 'client.ps1')

if (-not (Test-Path -LiteralPath $clientPath)) {
    [Console]::Error.WriteLine('[lan-share:install-required] O agente Windows não está instalado. Execute php artisan lan:install.')
    exit 2
}

& $clientPath -IdleTimeoutSeconds {{ idle_timeout }}{{ version_argument }}
POWERSHELL;
        $clientCommand = strtr($clientCommand, [
            '{{ idle_timeout }}' => (string) $idleTimeout,
            '{{ version_argument }}' => $versionArgument,
        ]);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $command = [
            'powershell.exe',
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $clientCommand,
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);

        if (! is_resource($process)) {
            throw new AgentUnavailableException('Não foi possível iniciar o cliente PowerShell do agente Windows.');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        $this->clientEnforcesVersion = $enforceVersion;
        stream_set_blocking($this->pipes[0], true);
        stream_set_blocking($this->pipes[1], true);
        stream_set_blocking($this->pipes[2], false);
    }

    private function readResource(string $name): string
    {
        $path = rtrim($this->resourceDirectory, '/\\').DIRECTORY_SEPARATOR.$name;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new AgentUnavailableException("Não foi possível ler o recurso do agente: $path");
        }

        return $contents;
    }

    private function compressBase64(string $contents): string
    {
        $compressed = gzencode($contents, 9, ZLIB_ENCODING_GZIP);

        if ($compressed === false) {
            throw new AgentUnavailableException('Não foi possível preparar os scripts do agente Windows.');
        }

        return base64_encode($compressed);
    }

    private function runtimeVersion(?string $agent = null, ?string $client = null): string
    {
        $agent ??= $this->readResource('agent.ps1');
        $client ??= $this->readResource('client.ps1');

        return substr(hash('sha256', $agent.$client), 0, 24);
    }

    private function runPowerShellCommand(string $command, int $timeout): ProcessResult
    {
        try {
            return Process::timeout($timeout)->run([
                'powershell.exe',
                '-NoLogo',
                '-NoProfile',
                '-NonInteractive',
                '-ExecutionPolicy',
                'Bypass',
                '-Command',
                $command,
            ]);
        } catch (Throwable $exception) {
            throw new AgentUnavailableException(
                "Não foi possível executar o PowerShell do agente Windows: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    private function runPowerShellScript(string $script, string $filename, string $failureMessage): ProcessResult
    {
        $result = $this->runPowerShellCommand($this->commandRenderer->render($script, $filename), 20);

        if (! $result->successful()) {
            $details = trim($result->errorOutput() ?: $result->output());

            throw new AgentUnavailableException($details !== '' ? "$failureMessage $details" : $failureMessage);
        }

        return $result;
    }

    private function outputValue(string $output, string $key): string
    {
        if (preg_match('/^'.preg_quote($key, '/').':(.*)$/m', $output, $matches) !== 1) {
            throw new AgentUnavailableException("O PowerShell não retornou o campo esperado: $key.");
        }

        return trim($matches[1]);
    }

    private function clientErrorOutput(): string
    {
        if (! is_resource($this->pipes[2] ?? null)) {
            return '';
        }

        $error = stream_get_contents($this->pipes[2]);

        return is_string($error) ? trim($error) : '';
    }

    private function clientFailure(string $error, string $fallback): RuntimeException
    {
        $message = $error !== '' ? $error : $fallback;

        if (str_contains($message, '[lan-share:install-required]') ||
            str_contains($message, 'não está instalado') ||
            str_contains($message, 'n�o est� instalado') ||
            str_contains($message, 'desatualizado')) {
            $message = str_contains($message, 'desatualizado')
                ? 'O agente Windows está desatualizado. Execute php artisan lan:install.'
                : 'O agente Windows não está instalado. Execute php artisan lan:install.';

            return new AgentInstallationRequiredException($message);
        }

        return new RuntimeException($message);
    }

    /** @param array<string, mixed> $response */
    private function responsePort(array $response, string $key): int
    {
        $value = $response[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        throw new RuntimeException("O agente Windows não retornou uma porta válida para $key.");
    }

    /** @param array<string, mixed> $response */
    private function responseString(array $response, string $key): string
    {
        $value = $response[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("O agente Windows não retornou $key.");
        }

        return trim($value);
    }

    public function __destruct()
    {
        $this->close();
    }
}
