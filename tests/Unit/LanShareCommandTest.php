<?php

use DougKusanagi\LaravelLanShare\Tests\Support\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;

uses(TestCase::class);

it('inclui o destino informado nos links gerados pelo Artisan', function () {
    $output = new BufferedOutput;
    $exitCode = $this->app->make(Kernel::class)->call('lan:share', [
        'url' => '/dashboard?tab=orders',
        '--host' => '192.168.10.160',
        '--laravel-port' => '18080',
        '--vite-port' => '18081',
        '--json' => true,
    ], $output);
    $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['pairing_ttl'])->toBe(180)
        ->and($payload['shared_url'])
        ->toMatch('#^http://192\.168\.10\.160:\d+/dashboard\?tab=orders$#')
        ->and($payload['share_page_url'])
        ->toMatch('#^http://localhost:\d+/__lan-share\?url=%2Fdashboard%3Ftab%3Dorders$#');
});

it('aceita desabilitar a expiração do QR Code pela opção do comando', function () {
    $output = new BufferedOutput;
    $exitCode = $this->app->make(Kernel::class)->call('lan:share', [
        '--host' => '192.168.10.162',
        '--laravel-port' => '18084',
        '--vite-port' => '18085',
        '--pairing-ttl' => '0',
        '--json' => true,
    ], $output);
    $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['pairing_ttl'])->toBe(0);
});

it('aceita o destino também pela opção --url', function () {
    $output = new BufferedOutput;
    $exitCode = $this->app->make(Kernel::class)->call('lan:share', [
        '--url' => '/login',
        '--host' => '192.168.10.161',
        '--laravel-port' => '18082',
        '--vite-port' => '18083',
        '--json' => true,
    ], $output);
    $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['shared_url'])
        ->toMatch('#^http://192\.168\.10\.161:\d+/login$#');
});
