<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Http;

use DougKusanagi\LaravelLanShare\Support\QrCodeRenderer;
use DougKusanagi\LaravelLanShare\Support\ShareLinkBuilder;
use Illuminate\Http\Response;

final class SharePageController
{
    public function __construct(
        private readonly ShareLinkBuilder $links,
        private readonly QrCodeRenderer $qrCodeRenderer,
    ) {}

    public function __invoke(): Response
    {
        $project = (string) config('app.name', 'Laravel');
        $url = rtrim((string) config('app.url'), '/');
        $whatsApp = $this->links->whatsAppUrl($project, $url);
        $availability = $this->links->availabilityMessage();
        $qr = $this->qrCodeRenderer->renderSvg($url);
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsonUrl = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $jsonText = json_encode($this->links->message($project, $url), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        $html = <<<HTML
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Compartilhar {$e($project)}</title>
<style>:root{color-scheme:light dark;font-family:system-ui,sans-serif}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0f172a;color:#e2e8f0}.card{width:min(92vw,430px);padding:2rem;border:1px solid #334155;border-radius:1.25rem;background:#1e293b;box-shadow:0 20px 50px #0005;text-align:center}h1{margin:.2rem 0}.url{display:block;margin:1rem 0;color:#7dd3fc;overflow-wrap:anywhere}.qr{display:inline-grid;padding:.7rem;border-radius:.8rem;background:white}.qr svg{display:block;width:min(64vw,260px);height:auto}.note{color:#cbd5e1}.actions{display:grid;gap:.7rem;margin-top:1.3rem}.button{border:0;border-radius:.7rem;padding:.85rem 1rem;font:inherit;font-weight:650;cursor:pointer;text-decoration:none;color:#082f49;background:#7dd3fc}.whatsapp{color:white;background:#16a34a}</style></head>
<body><main class="card"><p>Compartilhamento local</p><h1>{$e($project)}</h1><a class="url" href="{$e($url)}">{$e($url)}</a><div class="qr">{$qr}</div><p class="note">{$e($availability)}</p><div class="actions"><button class="button" id="share">Compartilhar</button><button class="button" id="copy">Copiar endereço</button><a class="button whatsapp" href="{$e($whatsApp)}">Enviar pelo WhatsApp</a></div></main>
<script>const url={$jsonUrl},text={$jsonText};async function copy(value){if(navigator.clipboard){await navigator.clipboard.writeText(value);return}const input=document.createElement('textarea');input.value=value;input.style.position='fixed';input.style.opacity='0';document.body.appendChild(input);input.select();document.execCommand('copy');input.remove()}document.querySelector('#copy').onclick=async()=>{await copy(url);document.querySelector('#copy').textContent='Copiado!'};document.querySelector('#share').onclick=async()=>{if(navigator.share){await navigator.share({title:document.title,text,url})}else{await copy(text);document.querySelector('#share').textContent='Mensagem copiada!'}}</script></body></html>
HTML;

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
