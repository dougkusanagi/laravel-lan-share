<?php

use DougKusanagi\LaravelLanShare\Tests\Support\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Hash;

uses(TestCase::class);

it('preserva o destino específico no QR Code e no redirecionamento do pareamento', function () {
    $this->actingAs(new GenericUser([
        'id' => 42,
        'password' => Hash::make('secret'),
    ]));

    $issue = $this->postJson('/__lan-share/pairing', [
        'password' => 'secret',
        'url' => '/dashboard',
    ]);

    $issue->assertOk()
        ->assertJsonPath('connect_url', fn (string $url): bool => str_contains($url, '/__lan-share/connect?url=%2Fdashboard#token='));

    $fragment = (string) parse_url((string) $issue->json('connect_url'), PHP_URL_FRAGMENT);
    parse_str($fragment, $token);

    $this->postJson('/__lan-share/connect?url=%2Fdashboard', [
        'token' => $token['token'] ?? '',
    ])->assertOk()
        ->assertJsonPath('redirect_url', 'http://localhost/dashboard');
});
