<?php

use DougKusanagi\LaravelLanShare\Tests\Support\TestCase;
use Illuminate\Auth\GenericUser;

uses(TestCase::class);

it('renderiza a página de compartilhamento com o acesso manual e a proteção de pareamento', function () {
    $this->get('/__lan-share')
        ->assertOk()
        ->assertSee('Conectar dispositivo')
        ->assertSee('Acesso manual')
        ->assertSee('Entre neste computador');
});

it('renderiza a página intermediária para consumir o QR Code no celular', function () {
    $this->get('/__lan-share/connect')
        ->assertOk()
        ->assertSee('Conectando dispositivo')
        ->assertSee('Acesso seguro');
});

it('renderiza a URL de destino recebida pelo link de compartilhamento', function () {
    $this->get('/__lan-share?url=%2Fdashboard%3Ftab%3Dorders')
        ->assertOk()
        ->assertSee('http://localhost/dashboard?tab=orders')
        ->assertSee('href="http://localhost/login"', false);

    $this->actingAs(new GenericUser(['id' => 42]))
        ->get('/__lan-share?url=%2Fdashboard%3Ftab%3Dorders')
        ->assertOk()
        ->assertSee('pairingTarget=');
});

it('renderiza o formulário protegido para um usuário autenticado', function () {
    $this->actingAs(new GenericUser(['id' => 42]))
        ->get('/__lan-share')
        ->assertOk()
        ->assertSee('Autorize um novo dispositivo')
        ->assertSee('pairing-form')
        ->assertSee('X-CSRF-TOKEN');
});
