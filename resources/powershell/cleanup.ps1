#requires -RunAsAdministrator

$ErrorActionPreference = 'Stop'

$RulePrefix = {{ firewall_rule_prefix }}
$StatePath = Join-Path (Join-Path $env:LOCALAPPDATA 'DougKusanagi\LaravelLanShare') 'state.json'

if (Test-Path -LiteralPath $StatePath) {
    $state = Get-Content -LiteralPath $StatePath -Raw | ConvertFrom-Json

    foreach ($mapping in @($state.mappings)) {
        if ($mapping.listenAddress -and $mapping.listenPort) {
            & netsh interface portproxy delete v4tov4 listenaddress=$mapping.listenAddress listenport=$mapping.listenPort | Out-Null
        }
    }
}

Get-NetFirewallRule -Name "$RulePrefix-*" -ErrorAction SilentlyContinue |
    Remove-NetFirewallRule -ErrorAction SilentlyContinue

if (Test-Path -LiteralPath $StatePath) {
    Remove-Item -LiteralPath $StatePath -Force
}

Write-Host 'Compartilhamento LAN removido.' -ForegroundColor Green
