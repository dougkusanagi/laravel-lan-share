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
        ]);
    }

    public function renderCleanup(string $firewallRulePrefix): string
    {
        return $this->render('cleanup.ps1', [
            '{{ firewall_rule_prefix }}' => $this->quote($firewallRulePrefix),
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
