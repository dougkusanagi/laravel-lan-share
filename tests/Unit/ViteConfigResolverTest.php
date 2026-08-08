<?php

use DougKusanagi\LaravelLanShare\Support\ViteConfigResolver;

it('usa o vite.lan.config.ts quando ele existe', function () {
    $root = sys_get_temp_dir().'/lan-share-vite-'.uniqid();
    mkdir($root, 0777, true);
    file_put_contents($root.'/vite.lan.config.ts', '');

    expect((new ViteConfigResolver)->resolve($root, 'vite.lan.config.ts'))->toBe('vite.lan.config.ts');

    unlink($root.'/vite.lan.config.ts');
    rmdir($root);
});

it('cai para o vite.config.ts quando o arquivo lan não existe', function () {
    $root = sys_get_temp_dir().'/lan-share-vite-'.uniqid();
    mkdir($root, 0777, true);

    expect((new ViteConfigResolver)->resolve($root, 'vite.lan.config.ts'))->toBe('vite.config.ts');

    rmdir($root);
});

it('cai para o vite.config.ts quando a config é vazia', function () {
    $root = sys_get_temp_dir().'/lan-share-vite-'.uniqid();
    mkdir($root, 0777, true);

    expect((new ViteConfigResolver)->resolve($root, ''))->toBe('vite.config.ts');

    rmdir($root);
});
