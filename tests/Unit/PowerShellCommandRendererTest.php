<?php

use DougKusanagi\LaravelLanShare\PowerShell\PowerShellCommandRenderer;

it('renderiza um comando PowerShell de uma linha que preserva o script original', function () {
    $script = "#requires -RunAsAdministrator\n& netsh @Arguments\nWrite-Host 'Olá LAN'\n";
    $command = (new PowerShellCommandRenderer)->render($script, 'lan-share.ps1');

    preg_match("/FromBase64String\('([^']+)'\)/", $command, $matches);

    expect($command)
        ->not->toContain(PHP_EOL)
        ->toContain("[IO.Path]::Combine([IO.Path]::GetTempPath(),'lan-share.ps1')")
        ->toContain('try{& $scriptPath}')
        ->toContain('Remove-Item -LiteralPath $scriptPath');

    expect($matches[1] ?? null)->not->toBeNull();
    expect(base64_decode($matches[1], true))->toBe("\xEF\xBB\xBF".$script);
});
