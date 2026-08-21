<?php

use DougKusanagi\LaravelLanShare\Http\Middleware\UseLocalViteOrigin;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('reescreve a origem Vite para localhost em páginas abertas no Windows', function () {
    $hotFile = tempnam(sys_get_temp_dir(), 'lan-share-hot-');
    file_put_contents($hotFile, 'http://192.168.10.77:5174');
    $middleware = new UseLocalViteOrigin((new Vite)->useHotFile($hotFile));
    $request = Request::create('http://localhost:8080/login');

    $response = $middleware->handle($request, fn (): Response => new Response(
        '<link href="http://192.168.10.77:5174/resources/css/app.css"><script src="http://192.168.10.77:5174/@vite/client"></script>',
        headers: ['Content-Type' => 'text/html; charset=UTF-8'],
    ));

    expect($response->getContent())
        ->toContain('http://localhost:5174/resources/css/app.css')
        ->toContain('http://localhost:5174/@vite/client')
        ->not->toContain('http://192.168.10.77:5174');

    unlink($hotFile);
});

it('mantém a origem LAN para páginas abertas por outros dispositivos', function () {
    $hotFile = tempnam(sys_get_temp_dir(), 'lan-share-hot-');
    file_put_contents($hotFile, 'http://192.168.10.77:5174');
    $middleware = new UseLocalViteOrigin((new Vite)->useHotFile($hotFile));
    $request = Request::create('http://192.168.10.77:8080/login');

    $response = $middleware->handle($request, fn (): Response => new Response(
        '<script src="http://192.168.10.77:5174/@vite/client"></script>',
        headers: ['Content-Type' => 'text/html; charset=UTF-8'],
    ));

    expect($response->getContent())->toContain('http://192.168.10.77:5174/@vite/client');

    unlink($hotFile);
});
