<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\PowerShell;

use DougKusanagi\LaravelLanShare\Support\LanSharePlan;
use RuntimeException;

final class PowerShellScriptRenderer
{
    private readonly string $templateDirectory;

    public function __construct(?string $templateDirectory = null)
    {
        $this->templateDirectory = $templateDirectory ?? dirname(__DIR__, 2).'/resources/powershell';
    }

    public function renderShare(LanSharePlan $plan): string
    {
        return $this->render('share.ps1', [
            '{{ firewall_rule_prefix }}' => $this->quote($plan->firewallRulePrefix),
            '{{ laravel_port }}' => (string) $plan->laravelPort,
            '{{ vite_port }}' => (string) $plan->vitePort,
            '{{ wsl_distro }}' => $this->quote($plan->wslDistro ?? ''),
            '{{ laravel_port_start }}' => (string) ($plan->laravelPortStart > 0 ? $plan->laravelPortStart : $plan->laravelPort),
            '{{ vite_port_start }}' => (string) ($plan->vitePortStart > 0 ? $plan->vitePortStart : $plan->vitePort),
            '{{ port_search_limit }}' => (string) $plan->portSearchLimit,
            '{{ state_key }}' => $this->quote($plan->stateKey),
        ]);
    }

    public function renderCleanup(
        string $firewallRulePrefix,
        string $stateKey = 'default',
        int $laravelPortStart = 8080,
        int $vitePortStart = 5174,
        int $portSearchLimit = 20,
        ?string $wslDistro = null,
    ): string
    {
        return $this->render('cleanup.ps1', [
            '{{ firewall_rule_prefix }}' => $this->quote($firewallRulePrefix),
            '{{ laravel_port_start }}' => (string) $laravelPortStart,
            '{{ vite_port_start }}' => (string) $vitePortStart,
            '{{ port_search_limit }}' => (string) max(1, $portSearchLimit),
            '{{ state_key }}' => $this->quote($stateKey),
            '{{ wsl_distro }}' => $this->quote($wslDistro ?? ''),
        ]);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function render(string $template, array $replacements): string
    {
        $path = $this->templateDirectory.'/'.$template;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Não foi possível ler o template {$path}.");
        }

        return strtr($contents, $replacements);
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
