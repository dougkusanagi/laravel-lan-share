<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Console\Commands;

use DougKusanagi\LaravelLanShare\Agent\WindowsAgentClient;
use DougKusanagi\LaravelLanShare\PowerShell\PowerShellCommandRenderer;
use DougKusanagi\LaravelLanShare\PowerShell\PowerShellScriptRenderer;
use DougKusanagi\LaravelLanShare\Support\ClipboardWriter;
use DougKusanagi\LaravelLanShare\Support\ScriptFileWriter;
use DougKusanagi\LaravelLanShare\Support\StateKeyResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('lan:share:cleanup {--script= : Save the PowerShell cleanup script to this path} {--legacy : Use the generated PowerShell cleanup script} {--no-script : Do not print the PowerShell command} {--raw-script : Also print the complete PowerShell script} {--copy : Copy the PowerShell command to the Windows clipboard}')]
#[Description('Remove o compartilhamento LAN pelo agente ou pelo script PowerShell legado')]
final class LanShareCleanupCommand extends Command
{
    public function __construct(
        private readonly PowerShellScriptRenderer $scriptRenderer,
        private readonly PowerShellCommandRenderer $commandRenderer,
        private readonly ClipboardWriter $clipboardWriter,
        private readonly ScriptFileWriter $scriptFileWriter,
        private readonly StateKeyResolver $stateKeyResolver,
        private readonly WindowsAgentClient $agentClient,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('legacy') &&
            ! $this->option('copy') &&
            ! $this->option('raw-script') &&
            $this->scriptPath() === '' &&
            (bool) config('lan-share.agent.enabled', true)) {
            try {
                $stopped = $this->agentClient->stopProject($this->stateKeyResolver->resolve(base_path()));
                $message = $stopped
                    ? 'Sessão do agente Windows encerrada e recursos removidos.'
                    : 'Nenhuma sessão ativa deste projeto foi encontrada no agente Windows.';
                $this->components->info($message);

                return self::SUCCESS;
            } catch (Throwable $exception) {
                $this->components->warn("Não foi possível usar o agente Windows: {$exception->getMessage()}");
            }
        }

        try {
            $script = $this->scriptRenderer->renderCleanup(
                (string) config('lan-share.firewall_rule_prefix', 'DougKusanagi-LaravelLanShare'),
                $this->stateKeyResolver->resolve(base_path()),
                (int) config('lan-share.laravel_port', 8080),
                (int) config('lan-share.vite_port', 5174),
                max(1, (int) config('lan-share.port_search_limit', 20)),
                $this->resolveDistro(),
            );
            $powerShellCommand = $this->commandRenderer->render($script, 'DougKusanagi-LaravelLanShare-Cleanup.ps1');
            $copiedToClipboard = false;

            if ($this->option('copy')) {
                $this->clipboardWriter->copy($powerShellCommand);
                $copiedToClipboard = true;
            }

            $path = $this->scriptPath();

            if ($path !== '') {
                $this->scriptFileWriter->write($path, $script);
                $this->components->info("Script salvo em {$path}");
            }

            if (! $this->option('no-script')) {
                $this->line('Cole esta linha única no PowerShell como Administrador:');
                $this->newLine();
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

            if ($copiedToClipboard) {
                $this->components->info('Comando PowerShell copiado para o clipboard do Windows.');
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveDistro(): ?string
    {
        $distro = trim((string) config('lan-share.wsl_distro'));

        return $distro === '' ? null : $distro;
    }

    private function scriptPath(): string
    {
        $path = $this->option('script');

        return is_string($path) ? trim($path) : '';
    }
}
