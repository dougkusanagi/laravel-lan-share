<?php

use DougKusanagi\LaravelLanShare\Support\ShareLinkBuilder;
use DougKusanagi\LaravelLanShare\Tests\Support\TestCase;
use InvalidArgumentException;

uses(TestCase::class);

it('aplica o destino à URL da aplicação e à página de compartilhamento', function () {
    $links = app(ShareLinkBuilder::class);

    expect($links->applicationUrl('http://192.168.1.20:8080', '/dashboard?tab=orders'))
        ->toBe('http://192.168.1.20:8080/dashboard?tab=orders')
        ->and($links->sharePageUrl('http://192.168.1.20:8080', '/dashboard?tab=orders'))
        ->toBe('http://192.168.1.20:8080/__lan-share?url=%2Fdashboard%3Ftab%3Dorders');
});

it('leva o destino até o QR Code de pareamento', function () {
    $url = app(ShareLinkBuilder::class)->pairingConnectUrl(
        'http://192.168.1.20:8080',
        'temporary-token',
        '/dashboard',
    );

    expect($url)->toBe('http://192.168.1.20:8080/__lan-share/connect?url=%2Fdashboard#token=temporary-token');
});

it('rejeita destinos que poderiam redirecionar para outro protocolo ou host', function () {
    $links = app(ShareLinkBuilder::class);

    expect(fn () => $links->normalizeTarget('javascript:alert(1)'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $links->normalizeTarget('//example.com/private'))
        ->toThrow(InvalidArgumentException::class);
});
