<?php

it('inclui o agente Windows persistente e o cliente sem exigir copiar comandos', function () {
    $agent = file_get_contents(dirname(__DIR__, 2).'/resources/powershell/agent.ps1');
    $client = file_get_contents(dirname(__DIR__, 2).'/resources/powershell/client.ps1');

    expect($agent)->not->toBeFalse()
        ->toContain('WaitForConnectionAsync')
        ->toContain("'heartbeat'")
        ->toContain('leaseSeconds')
        ->toContain('Get-PortProxyMappings')
        ->toContain('$isMirroredNetwork = $wslIp -eq $lanIp')
        ->toContain('if (-not $isMirroredNetwork)')
        ->toContain('Save-State')
        ->toContain("\$listenAddress = '0.0.0.0'")
        ->toContain('"listenaddress=$listenAddress", "listenport=$port"')
        ->toContain('System.Threading.Mutex')
        ->toContain('PipeAccessRights]::CreateNewInstance');

    expect($client)->not->toBeFalse()
        ->toContain('NamedPipeClientStream')
        ->toContain('[lan-share:install-required]')
        ->toContain('Start-Process')
        ->toContain('-Verb RunAs')
        ->toContain('ConvertFrom-Json');

    expect(strpos($agent, '# A posse exclusiva do mutex precisa ser confirmada'))
        ->toBeLessThan(strpos($agent, '# O estado persistido é considerado órfão'));
});
