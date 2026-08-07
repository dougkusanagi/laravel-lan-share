<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

final readonly class LanSharePlan
{
    public function __construct(
        public int $laravelPort,
        public int $vitePort,
        public int $portSearchLimit,
        public string $viteConfig,
        public string $firewallRulePrefix,
        public ?string $host,
        public ?string $wslDistro,
        public int $laravelPortStart = 0,
        public int $vitePortStart = 0,
        public string $stateKey = 'default',
    ) {}

    /**
     * @return array{
     *     laravel_port: int,
     *     vite_port: int,
     *     port_search_limit: int,
     *     vite_config: string,
     *     firewall_rule_prefix: string,
     *     host: string|null,
     *     wsl_distro: string|null,
     *     laravel_port_start: int,
     *     vite_port_start: int,
     *     state_key: string,
     *     urls: array{laravel: string|null, vite: string|null}
     * }
     */
    public function toArray(): array
    {
        return [
            'laravel_port' => $this->laravelPort,
            'vite_port' => $this->vitePort,
            'port_search_limit' => $this->portSearchLimit,
            'vite_config' => $this->viteConfig,
            'firewall_rule_prefix' => $this->firewallRulePrefix,
            'host' => $this->host,
            'wsl_distro' => $this->wslDistro,
            'laravel_port_start' => $this->laravelPortStart,
            'vite_port_start' => $this->vitePortStart,
            'state_key' => $this->stateKey,
            'urls' => [
                'laravel' => $this->url($this->laravelPort),
                'vite' => $this->url($this->vitePort),
            ],
        ];
    }

    public function url(int $port): ?string
    {
        if ($this->host === null) {
            return null;
        }

        $host = str_contains($this->host, ':') && ! str_starts_with($this->host, '[')
            ? "[{$this->host}]"
            : $this->host;

        return "http://{$host}:{$port}";
    }

    public function withRuntime(int $laravelPort, int $vitePort, ?string $host = null): self
    {
        return new self(
            laravelPort: $laravelPort,
            vitePort: $vitePort,
            portSearchLimit: $this->portSearchLimit,
            viteConfig: $this->viteConfig,
            firewallRulePrefix: $this->firewallRulePrefix,
            host: $host ?? $this->host,
            wslDistro: $this->wslDistro,
            laravelPortStart: $this->laravelPortStart,
            vitePortStart: $this->vitePortStart,
            stateKey: $this->stateKey,
        );
    }
}
