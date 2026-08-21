<?php

use DougKusanagi\LaravelLanShare\Tests\Support\TestCase;
use Illuminate\Auth\GenericUser;

uses(TestCase::class);

it('renderiza a página de compartilhamento com o acesso manual e a proteção de pareamento', function () {
    $this->get('/__lan-share')
        ->assertOk()
        ->assertSee('Conectar dispositivo')
        ->assertSee('Endereço para compartilhar')
        ->assertSee('Voltar pro sistema')
        ->assertDontSee('Abrir aplicação')
        ->assertSee('Entre neste computador');
});

it('força uma navegação completa quando o retorno vem de uma visita Inertia', function () {
    $this->withHeaders(['X-Inertia' => 'true'])
        ->get('/__lan-share')
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'http://localhost/__lan-share');
});

it('guarda a página LAN Share como destino depois do login', function () {
    $this->get('/__lan-share/login?url=%2Fdashboard')
        ->assertRedirect('/login');

    expect(session('url.intended'))->toBe('http://localhost/__lan-share?url=%2Fdashboard');
});

it('usa localhost nos controles abertos no Windows e mantém o endereço LAN para compartilhamento', function () {
    config()->set('app.url', 'http://192.168.10.77:8080');

    $this->withServerVariables(['HTTP_HOST' => 'localhost:8080'])
        ->get('/__lan-share?url=%2Fdashboard')
        ->assertOk()
        ->assertSee('href="http://localhost:8080/__lan-share/login"', false)
        ->assertSee('http://192.168.10.77:8080/dashboard')
        ->assertSee('href="http://localhost:8080"', false)
        ->assertSee('M20.5 3.5A11.8', false)
        ->assertSee('id="share"', false)
        ->assertSee('hidden', false)
        ->assertSee("typeof navigator.share==='function'", false);
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
        ->assertSee('href="http://localhost/__lan-share/login"', false);

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
