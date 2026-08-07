<?php

use DougKusanagi\LaravelLanShare\Support\QrCodeRenderer;

it('renderiza a URL da aplicação como QR Code para o terminal', function () {
    $qrCode = (new QrCodeRenderer)->render('http://192.0.2.10:8080');

    expect($qrCode)->toContain('█')
        ->and(strlen($qrCode))->toBeGreaterThan(100);
});

it('renderiza um QR Code SVG para a página de compartilhamento', function () {
    $svg = (new QrCodeRenderer)->renderSvg('http://192.0.2.10:8080');

    expect($svg)->toContain('<svg')->toContain('<path');
});
