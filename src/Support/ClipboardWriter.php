<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

interface ClipboardWriter
{
    public function copy(string $contents): void;
}
