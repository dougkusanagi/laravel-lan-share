<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Console\Commands;

use DougKusanagi\LaravelLanShare\Agent\WindowsAgentClient;
use DougKusanagi\LaravelLanShare\Support\StateKeyResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('lan:agent {action=status : Ação do agente: status, doctor, stop ou shutdown}')]
#[Description('Inspeciona e controla o agente Windows do compartilhamento LAN')]
final class LanShareAgentCommand extends Command
{
    public function __construct(
        private readonly WindowsAgentClient $agentClient,
        private readonly StateKeyResolver $stateKeyResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $actionArgument = $this->argument('action');
        $action = is_string($actionArgument) ? strtolower(trim($actionArgument)) : 'status';

        try {
            $payload = match ($action) {
                'status' => $this->agentClient->status(false),
                'doctor' => $this->doctor(),
                'stop' => $this->stopCurrentProject(),
                'shutdown' => $this->shutdown(),
                default => throw new \InvalidArgumentException("A ação '$action' não é suportada."),
            };

            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->agentClient->close();
        }
    }

    /** @return array<string, mixed> */
    private function doctor(): array
    {
        if (! $this->agentClient->isAvailable()) {
            return [
                'ok' => false,
                'powershell' => false,
                'message' => 'powershell.exe não está disponível no WSL.',
            ];
        }

        return [
            'ok' => true,
            'powershell' => true,
            'agent' => $this->agentClient->status(false),
        ];
    }

    /** @return array<string, mixed> */
    private function stopCurrentProject(): array
    {
        $stopped = $this->agentClient->stopProject($this->stateKeyResolver->resolve(base_path()));

        return [
            'ok' => true,
            'stopped' => $stopped,
            'project_id' => $this->stateKeyResolver->resolve(base_path()),
        ];
    }

    /** @return array<string, mixed> */
    private function shutdown(): array
    {
        $shutdown = $this->agentClient->shutdown();

        return [
            'ok' => true,
            'shutdown' => $shutdown,
        ];
    }
}
