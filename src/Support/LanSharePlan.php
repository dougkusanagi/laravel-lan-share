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
}
