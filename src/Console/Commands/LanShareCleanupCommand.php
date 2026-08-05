<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Console\Commands;

use DougKusanagi\LaravelLanShare\PowerShell\PowerShellScriptRenderer;
use DougKusanagi\LaravelLanShare\Support\ScriptFileWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('lan:share:cleanup {--script= : Save the PowerShell cleanup script to this path} {--no-script : Do not print the cleanup script}')]
#[Description('Gera o script PowerShell para remover o compartilhamento LAN')]
final class LanShareCleanupCommand extends Command
{
    public function __construct(
        private readonly PowerShellScriptRenderer $scriptRenderer,
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

            $path = trim((string) $this->option('script'));

            if ($path !== '') {
                $this->scriptFileWriter->write($path, $script);
                $this->components->info("Script salvo em {$path}");
            }

            if (! $this->option('no-script')) {
                $this->line('Abra o PowerShell como Administrador, cole e execute:');
                $this->newLine();
                $this->output->write($script);
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
