<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use BaconQrCode\Renderer\PlainTextRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class QrCodeRenderer
{
    public function render(string $url): string
    {
        return (new Writer(new PlainTextRenderer(margin: 2)))->writeString($url);
    }

    public function renderSvg(string $url): string
    {
        $renderer = new ImageRenderer(new RendererStyle(280, 4), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($url);

        return preg_replace('/^<\?xml[^>]+>\s*/', '', $svg) ?? $svg;
    }
}
