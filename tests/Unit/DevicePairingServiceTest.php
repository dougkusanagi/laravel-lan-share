<?php

use DougKusanagi\LaravelLanShare\Support\DevicePairingService;
use DougKusanagi\LaravelLanShare\Tests\Support\TestCase;

uses(TestCase::class);

it('emite um token temporário e permite apenas um consumo', function () {
    $service = app(DevicePairingService::class);
    $pairing = $service->issue('42');

    expect($pairing['pairing_id'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($pairing['token'])->toHaveLength(64)
        ->and($service->status($pairing['pairing_id']))->toMatchArray(['state' => 'pending'])
        ->and($service->consume($pairing['token']))->toMatchArray([
            'user_id' => '42',
            'pairing_id' => $pairing['pairing_id'],
        ])
        ->and($service->consume($pairing['token']))->toBeNull()
        ->and($service->status($pairing['pairing_id']))->toMatchArray(['state' => 'consumed']);
});

it('não revela tokens ausentes no status', function () {
    expect(app(DevicePairingService::class)->status('missing-pairing'))
        ->toBe(['state' => 'expired']);
});

it('revoga um pareamento que ainda não foi consumido', function () {
    $service = app(DevicePairingService::class);
    $pairing = $service->issue('42');

    $service->revoke($pairing['pairing_id']);

    expect($service->consume($pairing['token']))->toBeNull()
        ->and($service->status($pairing['pairing_id']))->toBe(['state' => 'expired']);
});

it('não permite que outro usuário revogue o pareamento', function () {
    $service = app(DevicePairingService::class);
    $pairing = $service->issue('42');

    $service->revoke($pairing['pairing_id'], '99');

    expect($service->consume($pairing['token']))->not->toBeNull();
});
