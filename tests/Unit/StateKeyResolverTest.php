<?php

use DougKusanagi\LaravelLanShare\Support\StateKeyResolver;

it('gera uma chave estável e distinta para cada projeto', function () {
    $resolver = new StateKeyResolver;

    expect($resolver->resolve('/home/developer/Sites/project-a'))
        ->toBe($resolver->resolve('/home/developer/Sites/project-a'))
        ->not->toBe($resolver->resolve('/home/developer/Sites/project-b'))
        ->toMatch('/^[a-f0-9]{16}$/');
});
