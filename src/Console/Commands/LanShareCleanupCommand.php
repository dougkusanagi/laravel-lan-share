<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Console\Commands;

use DougKusanagi\LaravelLanShare\PowerShell\PowerShellCommandRenderer;
use DougKusanagi\LaravelLanShare\PowerShell\PowerShellScriptRenderer;
use DougKusanagi\LaravelLanShare\Support\ClipboardWriter;
use DougKusanagi\LaravelLanShare\Support\ScriptFileWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('lan:share:cleanup {--script= : Save the PowerShell cleanup script to this path} {--no-script : Do not print the PowerShell command} {--raw-script : Also print the complete PowerShell script} {--copy : Copy the PowerShell command to the Windows clipboard}')]
#[Description('Gera o script PowerShell para remover o compartilhamento LAN')]
final class LanShareCleanupCommand extends Command
{
    public function __construct(
        private readonly PowerShellScriptRenderer $scriptRenderer,
        private readonly PowerShellCommandRenderer $commandRenderer,
        private readonly ClipboardWriter $clipboardWriter,
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
            $script = $this->scriptRenderer->renderCleanup(
                (string) config('lan-share.firewall_rule_prefix', 'DougKusanagi-LaravelLanShare'),
            );
            $powerShellCommand = $this->commandRenderer->render($script, 'DougKusanagi-LaravelLanShare-Cleanup.ps1');
            $copiedToClipboard = false;

            if ($this->option('copy')) {
                $this->clipboardWriter->copy($powerShellCommand);
                $copiedToClipboard = true;
            }

            $pathOption = $this->option('script');
            $path = is_string($pathOption) ? trim($pathOption) : '';

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
}
