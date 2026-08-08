<?php

use DougKusanagi\LaravelLanShare\Support\ViteLanConfigInstaller;

it('cria um wrapper LAN baseado no vite.config.ts', function () {
    $root = sys_get_temp_dir().'/lan-share-install-'.uniqid();
    mkdir($root, 0777, true);
    file_put_contents($root.'/vite.config.ts', "export default {};\n");

    $outcome = (new ViteLanConfigInstaller)->install($root);

    expect($outcome)->toBe('created')
        ->and(file_get_contents($root.'/vite.lan.config.ts'))
        ->toContain("import baseConfig from './vite.config';")
        ->toContain("host: '0.0.0.0'")
        ->toContain('origin: lanOrigin')
        ->toContain('strictPort: true')
        ->not->toContain('export default {};');

    unlink($root.'/vite.lan.config.ts');
    unlink($root.'/vite.config.ts');
    rmdir($root);
});

it('não sobrescreve o vite.lan.config.ts existente', function () {
    $root = sys_get_temp_dir().'/lan-share-install-'.uniqid();
    mkdir($root, 0777, true);
    file_put_contents($root.'/vite.config.ts', "export default {};\n");
    file_put_contents($root.'/vite.lan.config.ts', "export default { lan: true };\n");

    $outcome = (new ViteLanConfigInstaller)->install($root);

    expect($outcome)->toBe('exists')
        ->and(file_get_contents($root.'/vite.lan.config.ts'))->toBe("export default { lan: true };\n");

    unlink($root.'/vite.lan.config.ts');
    unlink($root.'/vite.config.ts');
    rmdir($root);
});

it('atualiza o vite.lan.config.ts antigo que era uma cópia simples', function () {
    $root = sys_get_temp_dir().'/lan-share-install-'.uniqid();
    mkdir($root, 0777, true);
    $defaultConfig = "export default {};\n";
    file_put_contents($root.'/vite.config.ts', $defaultConfig);
    file_put_contents($root.'/vite.lan.config.ts', $defaultConfig);

    $outcome = (new ViteLanConfigInstaller)->install($root);

    expect($outcome)->toBe('updated')
        ->and(file_get_contents($root.'/vite.lan.config.ts'))
        ->toContain("import baseConfig from './vite.config';")
        ->not->toBe($defaultConfig);

    unlink($root.'/vite.lan.config.ts');
    unlink($root.'/vite.config.ts');
    rmdir($root);
});

it('mantém um vite.config.lan.ts existente', function () {
    $root = sys_get_temp_dir().'/lan-share-install-'.uniqid();
    mkdir($root, 0777, true);
    file_put_contents($root.'/vite.config.ts', "export default {};\n");
    file_put_contents($root.'/vite.config.lan.ts', "export default { lan: true };\n");

    $outcome = (new ViteLanConfigInstaller)->install($root);

    expect($outcome)->toBe('exists')
        ->and(file_exists($root.'/vite.lan.config.ts'))->toBeFalse()
        ->and(file_get_contents($root.'/vite.config.lan.ts'))->toBe("export default { lan: true };\n");

    unlink($root.'/vite.config.lan.ts');
    unlink($root.'/vite.config.ts');
    rmdir($root);
});

it('reporta quando não existe vite.config.ts', function () {
    $root = sys_get_temp_dir().'/lan-share-install-'.uniqid();
    mkdir($root, 0777, true);

    $outcome = (new ViteLanConfigInstaller)->install($root);

    expect($outcome)->toBe('missing-vite-config');

    rmdir($root);
});
