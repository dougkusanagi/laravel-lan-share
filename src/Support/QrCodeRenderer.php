<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use BaconQrCode\Renderer\PlainTextRenderer;
use BaconQrCode\Writer;

final class QrCodeRenderer
{
    public function render(string $url): string
    {
        return (new Writer(new PlainTextRenderer(margin: 2)))->writeString($url);
    }
}
