<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Console\Commands;

use DougKusanagi\LaravelLanShare\PowerShell\PowerShellScriptRenderer;
use DougKusanagi\LaravelLanShare\Support\LanHostResolver;
use DougKusanagi\LaravelLanShare\Support\LanSharePlan;
use DougKusanagi\LaravelLanShare\Support\PortAllocator;
use DougKusanagi\LaravelLanShare\Support\QrCodeRenderer;
use DougKusanagi\LaravelLanShare\Support\ScriptFileWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\InvokedProcess as LaravelInvokedProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Throwable;

#[Signature('lan:share {--host= : Hostname or IP address used by other devices on the network} {--laravel-port= : Preferred Laravel port} {--vite-port= : Preferred Vite port} {--distro= : WSL distribution name} {--script= : Save the PowerShell setup script to this path} {--prepare-only : Generate the setup script without starting the development servers} {--no-script : Do not print the setup script} {--json : Print the plan as JSON and exit} {--qr : Print a terminal QR code when a host is available}')]
#[Description('Prepara e inicia o compartilhamento do ambiente Laravel/Vite na rede local')]
final class LanShareCommand extends Command
{
    public function __construct(
        private readonly LanHostResolver $hostResolver,
        private readonly PortAllocator $portAllocator,
        private readonly PowerShellScriptRenderer $scriptRenderer,
        private readonly QrCodeRenderer $qrCodeRenderer,
        private readonly ScriptFileWriter $scriptFileWriter,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $plan = $this->buildPlan();
            $script = $this->scriptRenderer->renderShare($plan);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            return $this->outputJson($plan, $script);
        }

        try {
            $this->displayPlan($plan, $script);
            $this->saveScriptIfRequested($script);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('prepare-only')) {
            return self::SUCCESS;
        }

        return $this->serve($plan);
    }

    private function buildPlan(): LanSharePlan
    {
        $searchLimit = max(1, (int) config('lan-share.port_search_limit', 20));
        $laravelPort = $this->portAllocator->find(
            $this->resolvePort('laravel-port', 'laravel_port'),
            $searchLimit,
        );
        $vitePort = $this->portAllocator->find(
            $this->resolvePort('vite-port', 'vite_port'),
            $searchLimit,
            [$laravelPort],
        );

        return new LanSharePlan(
            laravelPort: $laravelPort,
            vitePort: $vitePort,
            portSearchLimit: $searchLimit,
            viteConfig: (string) config('lan-share.vite_config', 'vite.lan.config.ts'),
            firewallRulePrefix: (string) config('lan-share.firewall_rule_prefix', 'DougKusanagi-LaravelLanShare'),
            host: $this->resolveHost(),
            wslDistro: $this->resolveDistro(),
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

    private function resolveHost(): ?string
    {
        $optionHost = trim((string) $this->option('host'));

        if ($optionHost !== '') {
            return trim($optionHost, '[]');
        }

        $configuredHostValue = config('lan-share.host');
        $configuredHost = is_string($configuredHostValue) ? trim($configuredHostValue) : '';

        if ($configuredHost !== '') {
            return trim($configuredHost, '[]');
        }

        $resolvedHost = $this->hostResolver->resolve();

        if ($resolvedHost !== null) {
            return $resolvedHost;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($appHost) && ! $this->isLocalHost($appHost)) {
            return trim($appHost, '[]');
        }

        return $this->hostResolver->resolve();
    }

    private function resolveDistro(): ?string
    {
        $optionDistro = trim((string) $this->option('distro'));

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

    private function displayPlan(LanSharePlan $plan, string $script): void
    {
        $this->components->info('Plano de compartilhamento LAN');
        $this->line("Laravel no WSL: http://0.0.0.0:{$plan->laravelPort}");
        $this->line("Vite no WSL:    http://0.0.0.0:{$plan->vitePort}");

        if ($plan->host !== null) {
            $this->line("URL Laravel:    {$plan->url($plan->laravelPort)}");
            $this->line("URL Vite:       {$plan->url($plan->vitePort)}");
        } else {
            $this->components->warn('O IP LAN do Windows não foi detectado. O script PowerShell tentará encontrá-lo.');
        }

        $this->newLine();
        $this->line('Abra o PowerShell como Administrador, cole e execute o script abaixo:');

        if (! $this->option('no-script')) {
            $this->newLine();
            $this->line('----- INÍCIO DO SCRIPT POWERSHELL -----');
            $this->output->write($script);

            if (! str_ends_with($script, PHP_EOL)) {
                $this->newLine();
            }

            $this->line('----- FIM DO SCRIPT POWERSHELL -----');
        }

        $this->newLine();
        $this->line('Para remover o compartilhamento: php artisan lan:share:cleanup --script=lan-share-cleanup.ps1');

        if ($this->option('qr')) {
            $this->displayQrCodes($plan);
        }
    }

    private function displayQrCodes(LanSharePlan $plan): void
    {
        if ($plan->host === null) {
            $this->components->warn('Não foi possível gerar o QR Code sem um IP LAN.');

            return;
        }

        foreach (['Laravel' => $plan->url($plan->laravelPort), 'Vite' => $plan->url($plan->vitePort)] as $name => $url) {
            if ($url === null) {
                continue;
            }

            $this->newLine();
            $this->line("QR Code — {$name} ({$url})");
            $this->line($this->qrCodeRenderer->render($url));
        }
    }

    private function saveScriptIfRequested(string $script): void
    {
        $path = trim((string) $this->option('script'));

        if ($path === '') {
            return;
        }

        $this->scriptFileWriter->write($path, $script);
        $this->components->info("Script salvo em {$path}");
    }

    private function outputJson(LanSharePlan $plan, string $script): int
    {
        $cleanupScript = $this->scriptRenderer->renderCleanup($plan->firewallRulePrefix);
        $payload = $plan->toArray() + [
            'powershell_script' => $script,
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

            $result = $process->wait();
        } catch (Throwable $exception) {
            $this->error("Não foi possível iniciar o ambiente de desenvolvimento: {$exception->getMessage()}");

            return self::FAILURE;
        } finally {
            if ($process instanceof InvokedProcess) {
                $this->stopProcess($process, $processId);
            }

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

        return sprintf(
            'npx concurrently -c "#93c5fd,#c4b5fd" "php artisan serve --host=0.0.0.0 --port=%d" "npm run dev -- --config %s --host=0.0.0.0 --port=%d" --names=\'server,vite\' --kill-others-on-fail',
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
