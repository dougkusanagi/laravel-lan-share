<?php

use DougKusanagi\LaravelLanShare\Support\PortAllocator;

it('seleciona a primeira porta livre a partir da preferência', function () {
    $allocator = new PortAllocator(
        fn (int $port): bool => $port === 8082,
    );

    expect($allocator->find(8080, 5))->toBe(8082);
});

it('não reutiliza uma porta reservada', function () {
    $allocator = new PortAllocator(
        fn (int $port): bool => true,
    );

    expect($allocator->find(8080, 5, [8080]))->toBe(8081);
});
