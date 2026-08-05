<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

interface PortAvailabilityProbe
{
    public function isAvailable(int $port): ?bool;
}
