<?php

use DougKusanagi\LaravelLanShare\Support\ViteLanConfigInstaller;

it('cria o vite.lan.config.ts copiando o vite.config.ts', function () {
    $root = sys_get_temp_dir().'/lan-share-install-'.uniqid();
    mkdir($root, 0777, true);
    file_put_contents($root.'/vite.config.ts', "export default {};\n");

    $outcome = (new ViteLanConfigInstaller)->install($root);

    expect($outcome)->toBe('created')
        ->and(file_get_contents($root.'/vite.lan.config.ts'))->toBe("export default {};\n");

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

it('reporta quando não existe vite.config.ts', function () {
    $root = sys_get_temp_dir().'/lan-share-install-'.uniqid();
    mkdir($root, 0777, true);

    $outcome = (new ViteLanConfigInstaller)->install($root);

    expect($outcome)->toBe('missing-vite-config');

    rmdir($root);
});
