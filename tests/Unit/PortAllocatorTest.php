<?php

use DougKusanagi\LaravelLanShare\Support\PortAllocator;
use DougKusanagi\LaravelLanShare\Support\PortAvailabilityProbe;

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

it('pula uma porta ocupada no Windows antes de escolher a próxima', function () {
    $windowsProbe = new class implements PortAvailabilityProbe
    {
        public function isAvailable(int $port): ?bool
        {
            return $port === 8080 ? false : true;
        }
    };

    $allocator = new PortAllocator(
        fn (int $port): bool => true,
        $windowsProbe,
    );

    expect($allocator->find(8080, 5))->toBe(8081);
});

it('evita a sonda Windows no caminho rápido do agente', function () {
    $windowsProbe = new class implements PortAvailabilityProbe
    {
        public int $calls = 0;

        public function isAvailable(int $port): ?bool
        {
            $this->calls++;

            return false;
        }
    };

    $allocator = new PortAllocator(fn (int $port): bool => true, $windowsProbe);

    expect($allocator->findLocal(8080))->toBe(8080)
        ->and($windowsProbe->calls)->toBe(0);
});
