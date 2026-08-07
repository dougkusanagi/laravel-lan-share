<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Console\Commands;

use DougKusanagi\LaravelLanShare\Agent\AgentShareSession;
use DougKusanagi\LaravelLanShare\Agent\AgentInstallationRequiredException;
use DougKusanagi\LaravelLanShare\Agent\WindowsAgentClient;
use DougKusanagi\LaravelLanShare\PowerShell\PowerShellCommandRenderer;
use DougKusanagi\LaravelLanShare\PowerShell\PowerShellScriptRenderer;
use DougKusanagi\LaravelLanShare\Support\ClipboardWriter;
use DougKusanagi\LaravelLanShare\Support\LanHostResolver;
use DougKusanagi\LaravelLanShare\Support\LanSharePlan;
use DougKusanagi\LaravelLanShare\Support\PortAllocator;
use DougKusanagi\LaravelLanShare\Support\PreviousShareProcessKiller;
use DougKusanagi\LaravelLanShare\Support\QrCodeRenderer;
use DougKusanagi\LaravelLanShare\Support\ScriptFileWriter;
use DougKusanagi\LaravelLanShare\Support\ShareLinkBuilder;
use DougKusanagi\LaravelLanShare\Support\StateKeyResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\InvokedProcess as LaravelInvokedProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Throwable;

#[Signature('lan:share {--host= : Hostname or IP address used by other devices on the network} {--laravel-port= : Preferred Laravel port} {--vite-port= : Preferred Vite port} {--distro= : WSL distribution name} {--script= : Save the PowerShell setup script to this path} {--prepare-only : Generate the setup script without starting the development servers} {--replace : Force stopping the previous LAN Share processes for this project} {--no-replace : Keep previous LAN Share processes for this project} {--legacy : Use the generated PowerShell script instead of the Windows agent} {--install : Install or update the Windows agent without asking for confirmation} {--no-script : Do not print the PowerShell command} {--raw-script : Also print the complete PowerShell script} {--copy : Copy the PowerShell command to the Windows clipboard} {--json : Print the plan as JSON and exit} {--qr : Force the terminal QR code} {--no-qr : Do not print the terminal QR code} {--no-share-links : Do not print sharing links}')]
#[Description('Prepara e inicia o compartilhamento do ambiente Laravel/Vite na rede local')]
final class LanShareCommand extends Command
{
    private ?AgentShareSession $agentSession = null;

    public function __construct(
        private readonly LanHostResolver $hostResolver,
        private readonly PortAllocator $portAllocator,
        private readonly PreviousShareProcessKiller $previousShareProcessKiller,
        private readonly PowerShellScriptRenderer $scriptRenderer,
        private readonly PowerShellCommandRenderer $commandRenderer,
        private readonly ClipboardWriter $clipboardWriter,
        private readonly QrCodeRenderer $qrCodeRenderer,
        private readonly ScriptFileWriter $scriptFileWriter,
        private readonly StateKeyResolver $stateKeyResolver,
        private readonly WindowsAgentClient $agentClient,
        private readonly ShareLinkBuilder $shareLinkBuilder,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $this->stopPreviousShareIfRequested();
            $useAgent = $this->shouldUseAgent();
            $plan = $this->buildPlan($useAgent);
            $plan = $this->prepareAgentIfEnabled($plan, $useAgent);
            [$script, $powerShellCommand] = $this->legacyArtifacts($plan);
        } catch (Throwable $exception) {
            $this->stopAgentSession();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $copiedToClipboard = $this->copyCommandIfRequested($powerShellCommand);
        } catch (Throwable $exception) {
            $this->stopAgentSession();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            return $this->outputJson($plan, $script, $powerShellCommand, $copiedToClipboard);
        }

        try {
            $this->displayPlan($plan, $script, $powerShellCommand);
            $this->saveScriptIfRequested($script);

            if ($copiedToClipboard) {
                $this->components->info('Comando PowerShell copiado para o clipboard do Windows.');
            }
        } catch (Throwable $exception) {
            $this->stopAgentSession();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('prepare-only')) {
            return self::SUCCESS;
        }

        return $this->serve($plan);
    }

    private function stopPreviousShareIfRequested(): void
    {
        if ($this->option('prepare-only') || $this->option('json')) {
            return;
        }

        $replaceExisting = (bool) $this->option('replace') || (
            (bool) config('lan-share.replace_existing', true) &&
            ! $this->option('no-replace')
        );

        if (! $replaceExisting) {
            return;
        }

        $viteConfig = (string) config('lan-share.vite_config', 'vite.lan.config.ts');
        $stoppedGroups = $this->previousShareProcessKiller->stop(base_path(), $viteConfig);

        if ($stoppedGroups > 0) {
            $this->components->info("Compartilhamento anterior encerrado ({$stoppedGroups} grupo(s) de processo).");
        }
    }

    private function buildPlan(bool $useAgent): LanSharePlan
    {
        $searchLimit = max(1, (int) config('lan-share.port_search_limit', 20));
        $laravelPortStart = $this->resolvePort('laravel-port', 'laravel_port');
        $vitePortStart = $this->resolvePort('vite-port', 'vite_port');
        $allocator = $useAgent ? 'findLocal' : 'find';
        $laravelPort = $this->portAllocator->{$allocator}(
            $laravelPortStart,
            $searchLimit,
        );
        $vitePort = $this->portAllocator->{$allocator}(
            $vitePortStart,
            $searchLimit,
            [$laravelPort],
        );

        return new LanSharePlan(
            laravelPort: $laravelPort,
            vitePort: $vitePort,
            portSearchLimit: $searchLimit,
            viteConfig: (string) config('lan-share.vite_config', 'vite.lan.config.ts'),
            firewallRulePrefix: (string) config('lan-share.firewall_rule_prefix', 'DougKusanagi-LaravelLanShare'),
            host: $this->resolveHost($useAgent),
            wslDistro: $this->resolveDistro(),
            laravelPortStart: $laravelPortStart,
            vitePortStart: $vitePortStart,
            stateKey: $this->stateKeyResolver->resolve(base_path()),
        );
    }

    private function resolvePort(string $option, string $configKey): int
    {
        $value = $this->option($option);
        $preferredPort = match (true) {
            $value === null || $value === '' => (int) config("lan-share.{$configKey}"),
            is_string($value) => filter_var($value, FILTER_VALIDATE_INT),
            default => false,
        };

        if (! is_int($preferredPort) || $preferredPort < 1 || $preferredPort > 65535) {
            throw new InvalidArgumentException("A porta informada para {$option} não é válida.");
        }

        return $preferredPort;
    }

    private function resolveHost(bool $useAgent): ?string
    {
        $optionHost = $this->stringOption('host');

        if ($optionHost !== '') {
            return trim($optionHost, '[]');
        }

        $configuredHostValue = config('lan-share.host');
        $configuredHost = is_string($configuredHostValue) ? trim($configuredHostValue) : '';

        if ($configuredHost !== '') {
            return trim($configuredHost, '[]');
        }

        if ($useAgent) {
            return null;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($appHost) && ! $this->isLocalHost($appHost)) {
            return trim($appHost, '[]');
        }

        $resolvedHost = $this->hostResolver->resolve();

        if ($resolvedHost !== null) {
            return $resolvedHost;
        }

        return null;
    }

    private function resolveDistro(): ?string
    {
        $optionDistro = $this->stringOption('distro');

        if ($optionDistro !== '') {
            return $optionDistro;
        }

        $configuredDistro = trim((string) config('lan-share.wsl_distro'));

        return $configuredDistro === '' ? null : $configuredDistro;
    }

    private function isLocalHost(string $host): bool
    {
        $normalizedHost = strtolower(trim($host, '[]'));

        return in_array($normalizedHost, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)
            || str_ends_with($normalizedHost, '.localhost')
            || str_ends_with($normalizedHost, '.test');
    }

    private function displayPlan(LanSharePlan $plan, string $script, string $powerShellCommand): void
    {
        $this->components->info('Plano de compartilhamento LAN');
        $this->line("Laravel no WSL: http://0.0.0.0:{$plan->laravelPort}");
        $this->line("Vite no WSL:    http://0.0.0.0:{$plan->vitePort}");

        if ($plan->host !== null) {
            $this->line("URL Laravel:    {$plan->url($plan->laravelPort)}");
            $this->line("URL Vite:       {$plan->url($plan->vitePort)}");
        } elseif ($this->agentSession === null) {
            $this->components->warn('O IP LAN do Windows não foi detectado. O script PowerShell tentará encontrá-lo.');
        }

        $this->newLine();
        if ($this->agentSession !== null) {
            $this->components->info('Agente Windows ativo: portproxy e Firewall serão removidos automaticamente.');
            $this->line("Sessão do agente: {$this->agentSession->sessionId}");
        } else {
            $this->line('Abra o PowerShell como Administrador, cole e execute o script abaixo:');
        }

        if ($this->agentSession === null && ! $this->option('no-script')) {
            $this->newLine();
            $this->line('Cole esta linha única no PowerShell como Administrador:');
            $this->line($powerShellCommand);

            if ($this->option('raw-script')) {
                $this->newLine();
                $this->line('----- INÍCIO DO SCRIPT POWERSHELL -----');
                $this->output->write($script);

                if (! str_ends_with($script, PHP_EOL)) {
                    $this->newLine();
                }

                $this->line('----- FIM DO SCRIPT POWERSHELL -----');
            }
        }

        $this->newLine();
        if ($this->agentSession === null) {
            $this->line('Para remover portproxy e firewall do Windows, gere e execute: php artisan lan:share:cleanup --script=lan-share-cleanup.ps1');
        } else {
            $this->line('O encerramento desta sessão removerá os recursos do Windows automaticamente.');
        }

        if ($this->shouldDisplayQrCode()) {
            $this->displayQrCode($plan);
        }

        $this->displayShareLinks($plan);
    }

    private function shouldDisplayQrCode(): bool
    {
        if ($this->option('prepare-only') || $this->option('no-qr')) {
            return false;
        }

        return (bool) $this->option('qr') || (bool) config('lan-share.qr.enabled', true);
    }

    private function displayQrCode(LanSharePlan $plan): void
    {
        if ($plan->host === null) {
            $this->components->warn('Não foi possível gerar o QR Code sem um IP LAN.');

            return;
        }

        $url = $plan->url($plan->laravelPort);

        if ($url !== null) {
            $this->newLine();
            $this->line("QR Code — Aplicação ({$url})");
            $this->line($this->qrCodeRenderer->render($url));
        }
    }

    private function displayShareLinks(LanSharePlan $plan): void
    {
        if ($this->option('prepare-only') || $this->option('no-share-links') || $plan->host === null) {
            return;
        }

        $url = $plan->url($plan->laravelPort);

        if ($url === null) {
            return;
        }

        $project = (string) config('app.name', basename(base_path()));
        $this->newLine();
        $this->components->info('Compartilhar com outros dispositivos');
        $this->newLine();
        $this->line('Disponibilidade');
        $this->line('  '.$this->shareLinkBuilder->availabilityMessage());

        if ((bool) config('lan-share.share_page.enabled', true)) {
            $this->newLine();
            $this->line('Página de compartilhamento');
            $this->line('  '.$this->shareLinkBuilder->sharePageUrl($url));
        }

        if ((bool) config('lan-share.sharing.whatsapp', true)) {
            $whatsAppUrl = $this->shareLinkBuilder->whatsAppUrl($project, $url);
            $this->newLine();
            $this->line('WhatsApp');
            $this->line('  '.$whatsAppUrl);
        }

        $this->newLine();
    }

    /** @return array{string, string} */
    private function legacyArtifacts(LanSharePlan $plan): array
    {
        if ($this->agentSession !== null) {
            return ['', ''];
        }

        $script = $this->scriptRenderer->renderShare($plan);

        return [
            $script,
            $this->commandRenderer->render($script, 'DougKusanagi-LaravelLanShare.ps1'),
        ];
    }

    private function saveScriptIfRequested(string $script): void
    {
        $path = $this->stringOption('script');

        if ($path === '') {
            return;
        }

        $this->scriptFileWriter->write($path, $script);
        $this->components->info("Script salvo em {$path}");
    }

    private function copyCommandIfRequested(string $powerShellCommand): bool
    {
        if (! $this->option('copy')) {
            return false;
        }

        $this->clipboardWriter->copy($powerShellCommand);

        return true;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }

    private function outputJson(LanSharePlan $plan, string $script, string $powerShellCommand, bool $copiedToClipboard): int
    {
        $cleanupScript = $this->scriptRenderer->renderCleanup(
            $plan->firewallRulePrefix,
            $plan->stateKey,
            $plan->laravelPortStart > 0 ? $plan->laravelPortStart : $plan->laravelPort,
            $plan->vitePortStart > 0 ? $plan->vitePortStart : $plan->vitePort,
            $plan->portSearchLimit,
            $plan->wslDistro,
        );
        $payload = $plan->toArray() + [
            'powershell_script' => $script,
            'powershell_command' => $powerShellCommand,
            'copied_to_clipboard' => $copiedToClipboard,
            'cleanup_script' => $cleanupScript,
            'serve_command' => $this->developmentCommand($plan),
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function serve(LanSharePlan $plan): int
    {
        $process = null;
        $processId = null;
        $receivedSignal = null;
        $previousSignalHandlers = [];
        $previousAsyncSignals = function_exists('pcntl_async_signals')
            ? pcntl_async_signals()
            : null;

        try {
            $process = Process::path(base_path())
                ->env($this->developmentEnvironment($plan))
                ->forever()
                ->options(['create_process_group' => true])
                ->start($this->developmentCommand($plan), function (string $type, string $output): void {
                    $this->output->write($output);
                });
            $processId = $process->id();
            $this->registerSignalHandlers($process, $processId, $receivedSignal, $previousSignalHandlers);

            $result = $this->waitForDevelopmentProcess($process);
        } catch (Throwable $exception) {
            $this->error("Não foi possível iniciar o ambiente de desenvolvimento: {$exception->getMessage()}");

            return self::FAILURE;
        } finally {
            if ($process instanceof InvokedProcess) {
                $this->stopProcess($process, $processId);
            }

            $this->stopAgentSession();

            foreach ($previousSignalHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            if ($previousAsyncSignals !== null) {
                pcntl_async_signals($previousAsyncSignals);
            }
        }

        if ($receivedSignal !== null) {
            return 128 + $receivedSignal;
        }

        if ($result->successful()) {
            return self::SUCCESS;
        }

        $this->error('Não foi possível iniciar o ambiente de desenvolvimento.');

        return $result->exitCode() ?? self::FAILURE;
    }

    private function prepareAgentIfEnabled(LanSharePlan $plan, bool $useAgent): LanSharePlan
    {
        if (! $useAgent) {
            return $plan;
        }

        try {
            $this->agentSession = $this->agentClient->prepare($plan);

            return $this->agentSession->plan;
        } catch (AgentInstallationRequiredException $exception) {
            $this->agentClient->close();

            if (! $this->shouldInstallAgent($exception)) {
                throw $exception;
            }

            $installation = $this->agentClient->install();
            $status = $installation['updated'] ? 'instalado/atualizado' : 'já estava atualizado';
            $this->components->info("Agente Windows $status.");
            $this->line("Diretório: {$installation['path']}");
            $this->agentSession = $this->agentClient->prepare($plan);

            return $this->agentSession->plan;
        } catch (Throwable $exception) {
            $this->agentClient->close();

            if (! (bool) config('lan-share.agent.fallback_to_script', true)) {
                throw $exception;
            }

            $this->components->warn('O agente Windows não pôde ser iniciado. O fluxo legado por script PowerShell será usado.');
            $this->components->warn($exception->getMessage());

            return $plan;
        }
    }

    private function shouldInstallAgent(AgentInstallationRequiredException $exception): bool
    {
        if ($this->option('install')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            throw new AgentInstallationRequiredException(
                $exception->getMessage().' Execute novamente com --install para instalar automaticamente.',
                previous: $exception,
            );
        }

        $this->components->warn($exception->getMessage());

        return $this->confirm('Deseja instalar ou atualizar o agente Windows agora?', true);
    }

    private function shouldUseAgent(): bool
    {
        if ($this->option('prepare-only') ||
            $this->option('json') ||
            $this->option('legacy') ||
            $this->option('copy') ||
            $this->option('raw-script') ||
            $this->stringOption('script') !== '' ||
            ! (bool) config('lan-share.agent.enabled', true)) {
            return false;
        }

        return true;
    }

    private function stopAgentSession(): void
    {
        if ($this->agentSession === null) {
            return;
        }

        try {
            $this->agentClient->stop($this->agentSession);
        } catch (Throwable $exception) {
            $this->components->warn("Não foi possível remover os recursos do agente Windows: {$exception->getMessage()}");
            $this->agentClient->close();
        } finally {
            $this->agentSession = null;
        }
    }

    private function waitForDevelopmentProcess(InvokedProcess $process): ProcessResult
    {
        $nextHeartbeat = microtime(true);
        $heartbeatInterval = max(2, (int) config('lan-share.agent.heartbeat_interval', 3));

        while ($process->running()) {
            if ($this->agentSession !== null && microtime(true) >= $nextHeartbeat) {
                try {
                    $this->agentClient->heartbeat($this->agentSession);
                } catch (Throwable $exception) {
                    $this->components->error("O heartbeat do agente Windows falhou: {$exception->getMessage()}");
                    $this->stopProcess($process, $process->id());
                    break;
                }

                $nextHeartbeat = microtime(true) + $heartbeatInterval;
            }

            usleep(200000);
        }

        $result = $process->wait();

        return $result;
    }

    /**
     * @param  array<int, callable|int>  $previousSignalHandlers
     */
    private function registerSignalHandlers(
        InvokedProcess $process,
        ?int $processId,
        ?int &$receivedSignal,
        array &$previousSignalHandlers,
    ): void {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        foreach ($this->terminationSignals() as $signal) {
            $previousSignalHandlers[$signal] = function_exists('pcntl_signal_get_handler')
                ? pcntl_signal_get_handler($signal)
                : (defined('SIG_DFL') ? SIG_DFL : 0);

            pcntl_signal($signal, function (int $signal) use ($process, $processId, &$receivedSignal): void {
                $receivedSignal = $signal;
                $this->stopProcess($process, $processId);
            });
        }
    }

    /**
     * @return list<int>
     */
    private function terminationSignals(): array
    {
        return array_values(array_filter([
            defined('SIGINT') ? SIGINT : null,
            defined('SIGTERM') ? SIGTERM : null,
            defined('SIGQUIT') ? SIGQUIT : null,
        ], is_int(...)));
    }

    private function developmentCommand(LanSharePlan $plan): string
    {
        $viteConfig = preg_match('/^[A-Za-z0-9._\\/-]+$/', $plan->viteConfig) === 1
            ? $plan->viteConfig
            : escapeshellarg($plan->viteConfig);

        $concurrently = is_executable(base_path('node_modules/.bin/concurrently'))
            ? './node_modules/.bin/concurrently'
            : 'npx --no-install concurrently';

        return sprintf(
            '%s -c "#93c5fd,#c4b5fd" "php artisan serve --host=0.0.0.0 --port=%d" "npm run dev -- --config %s --host=0.0.0.0 --port=%d" --names=\'server,vite\' --kill-others-on-fail',
            $concurrently,
            $plan->laravelPort,
            $viteConfig,
            $plan->vitePort,
        );
    }

    /**
     * @return array<string, string>
     */
    private function developmentEnvironment(LanSharePlan $plan): array
    {
        if ($plan->host === null) {
            return [];
        }

        $host = str_contains($plan->host, ':') && ! str_starts_with($plan->host, '[')
            ? "[{$plan->host}]"
            : $plan->host;

        return [
            'APP_URL' => "http://{$host}:{$plan->laravelPort}",
            'VITE_DEV_ORIGIN' => "http://{$host}:{$plan->vitePort}",
        ];
    }

    private function stopProcess(InvokedProcess $process, ?int $processId = null): void
    {
        if (! $process->running()) {
            return;
        }

        $processId ??= $process->id();

        $this->stopProcessGroup($processId, $this->terminationSignal());

        if ($process instanceof LaravelInvokedProcess) {
            $process->stop(5, $this->killSignal());
        } else {
            $process->signal($this->killSignal());
        }

        $this->stopProcessGroup($processId, $this->killSignal());
    }

    private function stopProcessGroup(?int $processId, int $signal): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_getpgid') || ! function_exists('posix_getpgrp') || ! function_exists('posix_kill')) {
            return;
        }

        if (! is_int($processId)) {
            return;
        }

        $processGroupId = posix_getpgid($processId);

        if (! is_int($processGroupId) || $processGroupId <= 0 || $processGroupId === posix_getpgrp()) {
            return;
        }

        @posix_kill(-$processGroupId, $signal);
    }

    private function terminationSignal(): int
    {
        return defined('SIGTERM') ? SIGTERM : 15;
    }

    private function killSignal(): int
    {
        return defined('SIGKILL') ? SIGKILL : 9;
    }
}
