<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Console\Commands;

use DougKusanagi\LaravelLanShare\Agent\WindowsAgentClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('lan:uninstall {--force : Desinstala sem pedir confirmação}')]
#[Description('Encerra e remove o agente Windows do LAN Share')]
final class LanUninstallCommand extends Command
{
    public function __construct(private readonly WindowsAgentClient $agentClient)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Desinstalar o agente Windows do LAN Share?')) {
            $this->components->info('Desinstalação cancelada.');

            return self::SUCCESS;
        }

        try {
            $result = $this->agentClient->uninstall();
            $message = $result['removed'] ? 'Agente Windows desinstalado.' : 'O agente Windows já não estava instalado.';
            $this->components->info($message);
            $this->line("Diretório: {$result['path']}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->agentClient->close();
        }
    }
}
