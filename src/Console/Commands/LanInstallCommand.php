<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Console\Commands;

use DougKusanagi\LaravelLanShare\Agent\WindowsAgentClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('lan:install')]
#[Description('Instala ou atualiza o agente Windows usado pelo LAN Share')]
final class LanInstallCommand extends Command
{
    public function __construct(private readonly WindowsAgentClient $agentClient)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->agentClient->install();
            $status = $result['updated'] ? 'instalado/atualizado' : 'já estava atualizado';
            $this->components->info("Agente Windows $status.");
            $this->line("Versão: {$result['version']}");
            $this->line("Diretório: {$result['path']}");

            if ($result['agent_stopped']) {
                $this->line('A instância anterior foi encerrada; lan:share iniciará a nova versão.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->agentClient->close();
        }
    }
}
