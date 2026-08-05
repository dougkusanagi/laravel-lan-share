<?php

use DougKusanagi\LaravelLanShare\PowerShell\PowerShellScriptRenderer;
use DougKusanagi\LaravelLanShare\Support\LanSharePlan;

it('renderiza o setup PowerShell com regras idempotentes e validações', function () {
    $plan = new LanSharePlan(
        laravelPort: 8080,
        vitePort: 5174,
        portSearchLimit: 20,
        viteConfig: 'vite.lan.config.ts',
        firewallRulePrefix: 'DougKusanagi-LaravelLanShare',
        host: '192.168.10.160',
        wslDistro: null,
    );

    $script = (new PowerShellScriptRenderer)->renderShare($plan);

    expect($script)
        ->toContain('#requires -RunAsAdministrator')
        ->toContain("Invoke-Netsh -Arguments @('interface', 'portproxy', 'add', 'v4tov4'")
        ->toContain('New-NetFirewallRule')
        ->toContain('Test-NetConnection')
        ->toContain("\$RulePrefix = 'DougKusanagi-LaravelLanShare'");
});

it('renderiza o cleanup apenas para recursos gerenciados pelo pacote', function () {
    $script = (new PowerShellScriptRenderer)->renderCleanup('DougKusanagi-LaravelLanShare');

    expect($script)
        ->toContain('#requires -RunAsAdministrator')
        ->toContain('Remove-NetFirewallRule')
        ->toContain('portproxy delete')
        ->toContain("\$RulePrefix = 'DougKusanagi-LaravelLanShare'");
});
