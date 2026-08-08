<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Console\Commands;

use DougKusanagi\LaravelLanShare\Agent\WindowsAgentClient;
use DougKusanagi\LaravelLanShare\Support\ViteLanConfigInstaller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('lan:install')]
#[Description('Instala ou atualiza o agente Windows usado pelo LAN Share')]
final class LanInstallCommand extends Command
{
    public function __construct(
        private readonly WindowsAgentClient $agentClient,
        private readonly ViteLanConfigInstaller $viteLanConfigInstaller,
    ) {
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

            $this->ensureViteLanConfig();

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->agentClient->close();
        }
    }

    private function ensureViteLanConfig(): void
    {
        $outcome = $this->viteLanConfigInstaller->install();

        match ($outcome) {
            'created' => $this->components->info('Criado vite.lan.config.ts com a configuração LAN baseada no vite.config.ts do projeto.'),
            'updated' => $this->components->info('Atualizado vite.lan.config.ts antigo para usar a configuração LAN.'),
            'exists' => $this->components->info('A configuração Vite LAN já existe; mantida como está.'),
            default => $this->components->warn('vite.config.ts não encontrado; o compartilhamento usará a configuração padrão.'),
        };
    }
}
