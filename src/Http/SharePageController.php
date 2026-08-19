<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Http;

use DougKusanagi\LaravelLanShare\Support\ShareLinkBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class SharePageController
{
    public function __construct(private readonly ShareLinkBuilder $links) {}

    public function __invoke(Request $request): Response
    {
        $project = (string) config('app.name', 'Laravel');
        $target = $this->requestTarget($request);
        $baseUrl = $this->applicationUrl($request);
        $url = $this->links->applicationUrl($baseUrl, $target);
        $whatsApp = $this->links->whatsAppUrl($project, $url);
        $availability = $this->links->availabilityMessage();
        $isAuthenticated = $request->user() !== null;
        $pairingEnabled = (bool) config('lan-share.pairing.enabled', true);
        $csrfToken = $request->session()->token();
        $pagePath = trim((string) config('lan-share.share_page.path', '__lan-share'), '/');

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $json = static fn (mixed $value): string => json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );

        $loginUrl = rtrim($baseUrl, '/').'/login';
        $pairingMarkup = $this->pairingMarkup($isAuthenticated, $pairingEnabled, $e, $loginUrl);
        $projectJson = $json($project);

        $html = <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{$e($csrfToken)}">
<meta name="theme-color" content="#f4f0e8">
<title>Compartilhar {$e($project)}</title>
<style>
:root{font-family:"Gill Sans","Avenir Next","Segoe UI",sans-serif;color:#17201f;background:#f4f0e8;font-synthesis:none;text-rendering:optimizeLegibility;--ink:#17201f;--muted:#68736f;--line:#d9ddd6;--primary:#e4583f;--primary-dark:#c94331;--surface:#fffdf8}
*{box-sizing:border-box}body{margin:0;min-height:100svh;background:radial-gradient(circle at 7% 7%,#ffd9ce 0,transparent 28%),radial-gradient(circle at 96% 90%,#d8e7df 0,transparent 34%),#f4f0e8}button,input{font:inherit}.page{width:min(1160px,calc(100% - 40px));margin:0 auto;padding:32px 0 42px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:42px}.brand{display:inline-flex;align-items:center;gap:11px;color:var(--ink);font-size:14px;font-weight:760;letter-spacing:-.02em}.brand-mark{display:grid;place-items:center;width:34px;height:34px;border-radius:11px;color:#fff;background:#17201f;box-shadow:0 7px 16px rgba(23,32,31,.2)}.brand-mark svg{width:18px;height:18px}.network{display:inline-flex;align-items:center;gap:7px;border:1px solid #b9d8ca;border-radius:999px;padding:7px 11px;color:#257253;background:#eef8f1;font-size:11px;font-weight:700;letter-spacing:.02em}.network-dot{width:7px;height:7px;border-radius:99px;background:#4d9a74;box-shadow:0 0 0 4px #d9f0e1}.hero{display:grid;grid-template-columns:minmax(0,.82fr) minmax(440px,1.18fr);align-items:center;gap:72px;margin-bottom:28px}.eyebrow{display:flex;align-items:center;gap:8px;margin:0 0 17px;color:#c94331;font-size:11px;font-weight:800;letter-spacing:.15em;text-transform:uppercase}.eyebrow-line{width:28px;height:1px;background:#e88b79}.hero h1{max-width:540px;margin:0;color:var(--ink);font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;font-size:clamp(36px,5vw,64px);font-weight:500;line-height:1.03;letter-spacing:-.065em}.hero h1 em{font-style:italic;color:#c94331}.hero-lede{max-width:470px;margin:22px 0 0;color:var(--muted);font-size:16px;line-height:1.65}.feature-row{display:flex;flex-wrap:wrap;gap:9px;margin-top:28px}.feature{display:inline-flex;align-items:center;gap:7px;border:1px solid rgba(126,142,132,.3);border-radius:999px;padding:8px 11px;color:#53615a;background:rgba(255,253,248,.68);font-size:12px;font-weight:650}.feature svg{width:14px;height:14px;color:#c94331}.share-card{border:1px solid rgba(217,221,214,.95);border-radius:26px;background:rgba(255,253,248,.92);box-shadow:0 28px 75px rgba(38,46,40,.11),0 6px 18px rgba(38,46,40,.05);overflow:hidden;backdrop-filter:blur(16px)}.card-inner{padding:27px}.card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:22px}.card-title{display:flex;gap:13px;align-items:flex-start}.card-icon{display:grid;flex:none;place-items:center;width:40px;height:40px;border-radius:13px;color:#c94331;background:#fff0eb}.card-icon svg{width:20px;height:20px}.card-kicker{margin:1px 0 5px;color:#96a09a;font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.card-head h2{margin:0;font-size:18px;letter-spacing:-.035em}.badge{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:6px 9px;color:#b64030;background:#fff0eb;font-size:10px;font-weight:800;white-space:nowrap}.badge svg{width:12px;height:12px}.pairing-panel{border:1px solid #e2e6df;border-radius:18px;background:linear-gradient(145deg,#fffdf8,#f8faf6);padding:20px}.pairing-copy{display:flex;gap:12px;align-items:flex-start}.pairing-copy svg{flex:none;width:19px;height:19px;margin-top:2px;color:#c94331}.pairing-copy h3{margin:0;font-size:14px;letter-spacing:-.015em}.pairing-copy p{margin:6px 0 0;color:var(--muted);font-size:12px;line-height:1.5}.pairing-form{margin-top:18px}.field-label{display:block;margin:0 0 7px;color:#3f4b45;font-size:11px;font-weight:750}.input-row{display:flex;gap:9px}.input{min-width:0;flex:1;border:1px solid #c9d2c9;border-radius:11px;outline:none;padding:10px 12px;color:var(--ink);background:#fffdf8;font-size:13px;transition:border-color .18s,box-shadow .18s}.input::placeholder{color:#9da8a1}.input:focus{border-color:#e88b79;box-shadow:0 0 0 3px rgba(232,139,121,.2)}.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:11px;padding:10px 13px;cursor:pointer;font-size:12px;font-weight:760;transition:transform .18s,box-shadow .18s,background .18s}.button:hover{transform:translateY(-1px)}.button:disabled{cursor:wait;opacity:.65;transform:none}.button svg{width:15px;height:15px}.button-primary{color:#fffdf8;background:var(--primary);box-shadow:0 8px 16px rgba(228,88,63,.22)}.button-primary:hover{background:var(--primary-dark);box-shadow:0 10px 20px rgba(228,88,63,.28)}.helper{margin:9px 0 0;color:#96a09a;font-size:11px;line-height:1.45}.guest{display:flex;align-items:center;justify-content:space-between;gap:14px;border:1px solid #f3c4b9;border-radius:13px;padding:12px 13px;color:#a54232;background:#fff5f1;font-size:12px;line-height:1.45}.guest p{margin:0}.button-ghost{padding:8px 10px;color:#b64030;background:transparent}.button-ghost:hover{background:#ffe6df}.pairing-active{margin-top:1px}.qr-shell{position:relative;display:grid;place-items:center;width:min(100%,276px);aspect-ratio:1;margin:0 auto 17px;border:1px solid #d9ddd6;border-radius:18px;padding:15px;background:#fffdf8;box-shadow:0 10px 28px rgba(38,46,40,.08);overflow:hidden}.qr-shell svg{display:block;width:100%;height:100%;transition:filter .35s,opacity .35s}.qr-shell.is-blurred svg{filter:blur(13px);opacity:.24}.qr-overlay{position:absolute;inset:0;display:grid;place-items:center;padding:20px;text-align:center;background:rgba(255,253,248,.7);backdrop-filter:blur(2px)}.qr-overlay[hidden]{display:none}.qr-overlay-inner{display:grid;justify-items:center;gap:7px;color:#3f4b45;font-size:12px;font-weight:750}.qr-overlay-icon{display:grid;place-items:center;width:36px;height:36px;border-radius:12px;color:#c94331;background:#fff0eb}.qr-overlay-icon svg{width:18px;height:18px;filter:none;opacity:1}.active-meta{text-align:center}.active-status{display:inline-flex;align-items:center;gap:6px;color:#b64030;font-size:12px;font-weight:760}.active-status-dot{width:7px;height:7px;border-radius:999px;background:#e4583f;box-shadow:0 0 0 4px #ffe3dc}.active-help{margin:8px 0 0;color:var(--muted);font-size:11px;line-height:1.5}.timer{margin-top:10px;color:#96a09a;font-size:11px}.button-reset{width:100%;margin-top:17px;color:#b64030;background:#fff0eb}.button-reset:hover{background:#ffe3dc}.pairing-error{display:none;margin:12px 0 0;border-radius:10px;padding:9px 11px;color:#a33a2c;background:#fff0eb;font-size:11px;line-height:1.45}.pairing-error.is-visible{display:block}.manual{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-top:18px;padding-top:18px;border-top:1px solid #edf0eb}.manual-copy{min-width:0}.manual-kicker{margin:0 0 5px;color:#96a09a;font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.manual-url{display:block;max-width:310px;overflow:hidden;color:#53615a;font-size:12px;text-overflow:ellipsis;white-space:nowrap}.manual-actions{display:flex;flex:none;gap:7px}.icon-button{display:inline-grid;place-items:center;width:34px;height:34px;border:1px solid #d9ddd6;border-radius:10px;color:#68736f;background:#fffdf8;cursor:pointer;transition:all .18s}.icon-button:hover{border-color:#f3c4b9;color:#c94331;background:#fff0eb}.icon-button svg{width:15px;height:15px}.whatsapp-button{color:#257253}.whatsapp-button:hover{border-color:#b9d8ca;color:#257253;background:#eef8f1}.availability{display:flex;align-items:flex-start;gap:8px;margin-top:17px;color:#96a09a;font-size:11px;line-height:1.5}.availability svg{flex:none;width:14px;height:14px;margin-top:1px;color:#68736f}.footer{display:flex;justify-content:space-between;gap:12px;margin-top:24px;color:#96a09a;font-size:11px}.footer a{color:#68736f;text-decoration:none}.footer a:hover{color:#c94331}.hidden{display:none!important}
@media (max-width:900px){.hero{grid-template-columns:1fr;gap:30px}.hero-copy{max-width:650px}.hero h1{font-size:clamp(38px,9vw,58px)}.hero-lede{font-size:15px}.topbar{margin-bottom:30px}}
@media (max-width:560px){.page{width:min(100% - 24px,520px);padding:20px 0 28px}.topbar{margin-bottom:28px}.network{padding:6px 8px;font-size:10px}.brand{font-size:13px}.brand-mark{width:31px;height:31px}.hero{gap:24px}.hero h1{font-size:42px}.hero-lede{margin-top:16px;font-size:14px}.feature-row{margin-top:20px}.feature{font-size:11px}.card-inner{padding:19px}.share-card{border-radius:21px}.pairing-panel{padding:15px}.input-row{display:grid;grid-template-columns:1fr}.input-row .button{min-height:42px}.manual{align-items:flex-start}.manual-actions{padding-top:2px}.footer{display:block;line-height:1.6}.footer span{display:block;margin-bottom:4px}}
</style>
</head>
<body>
<main class="page">
<header class="topbar"><div class="brand"><span class="brand-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 2.9 8.3 7 10 4.1-1.7 7-5.5 7-10V6l-7-3Z"/><path d="m9.4 12 1.7 1.7 3.7-3.7"/></svg></span>LAN Share</div><div class="network"><span class="network-dot"></span>Rede local ativa</div></header>
<section class="hero"><div class="hero-copy"><p class="eyebrow"><span class="eyebrow-line"></span>Compartilhamento local</p><h1>Leve seu projeto <em>com você.</em></h1><p class="hero-lede">Conecte o celular ao ambiente de desenvolvimento em segundos. Um QR Code temporário, uma sessão segura e zero endereço para digitar.</p><div class="feature-row"><span class="feature"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 2.9 8.3 7 10 4.1-1.7 7-5.5 7-10V6l-7-3Z"/><path d="m9.4 12 1.7 1.7 3.7-3.7"/></svg>Token de uso único</span><span class="feature"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>Expira em instantes</span></div></div>
<article class="share-card"><div class="card-inner"><div class="card-head"><div class="card-title"><span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/><path d="M14 14h3v3h-3zM18 18h3v3h-3zM21 14h-3"/></svg></span><div><p class="card-kicker">{$e($project)}</p><h2>Conectar dispositivo</h2></div></div><span class="badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 2.9 8.3 7 10 4.1-1.7 7-5.5 7-10V6l-7-3Z"/><path d="m9.4 12 1.7 1.7 3.7-3.7"/></svg>Seguro</span></div>
{$pairingMarkup}
<div class="manual"><div class="manual-copy"><p class="manual-kicker">Acesso manual</p><code class="manual-url" title="{$e($url)}">{$e($url)}</code></div><div class="manual-actions"><button class="icon-button" id="copy" type="button" title="Copiar endereço" aria-label="Copiar endereço"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="8" x="8" y="8" rx="1"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/></svg></button><button class="icon-button" id="share" type="button" title="Compartilhar endereço" aria-label="Compartilhar endereço"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/></svg></button><a class="icon-button whatsapp-button" href="{$e($whatsApp)}" title="Enviar pelo WhatsApp" aria-label="Enviar pelo WhatsApp"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.5 3.5A11.8 11.8 0 0 0 12.1 0C5.6 0 .3 5.3.3 11.8c0 2.1.6 4.1 1.6 5.9L.2 24l6.5-1.7a11.8 11.8 0 0 0 5.4 1.3h.1c6.5 0 11.8-5.3 11.8-11.8 0-3.1-1.2-6-3.5-8.3ZM12.1 21.6c-1.7 0-3.4-.5-4.9-1.3l-.4-.2-3.9 1 1-3.8-.3-.4a9.7 9.7 0 0 1-1.5-5.2c0-5.4 4.4-9.8 9.9-9.8 2.6 0 5.1 1 6.9 2.9a9.7 9.7 0 0 1 2.9 6.9c0 5.5-4.5 9.9-9.9 9.9Zm5.4-7.4c-.3-.2-1.8-.9-2.1-1-.3-.1-.5-.2-.7.2-.2.3-.8 1-.9 1.2-.2.2-.3.2-.6.1-1.6-.8-2.7-1.4-3.8-3.2-.3-.5.3-.4.8-1.4.1-.2.1-.4 0-.5 0-.2-.7-1.7-.9-2.3-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.5s1 2.9 1.1 3.1c.1.2 2 3.1 4.8 4.3 1.8.8 2.5.9 3.4.8.5-.1 1.8-.7 2-1.3.3-.6.3-1.2.2-1.3-.1-.2-.3-.3-.6-.4Z"/></svg></a></div></div><p class="availability"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a9 9 0 0 0-9 9c0 4.2 2.8 7.7 6.7 8.7L12 22l2.3-2.3A9 9 0 0 0 21 11a9 9 0 0 0-9-9Z"/><path d="M8.5 11.5h.01M12 11.5h.01M15.5 11.5h.01"/></svg>{$e($availability)}</p></div></article></section>
<footer class="footer"><span>Uma experiência de desenvolvimento local do {$e($project)}</span><a href="{$e($url)}">Abrir aplicação <span aria-hidden="true">↗</span></a></footer>
</main>
<script>
const appUrl={$json($url)},projectName={$projectJson},pagePath={$json('/'.$pagePath)},csrfToken={$json($csrfToken)},isAuthenticated={$json($isAuthenticated)};
const copyButton=document.querySelector('#copy'),shareButton=document.querySelector('#share');
async function copy(value){if(navigator.clipboard){await navigator.clipboard.writeText(value);return}const input=document.createElement('textarea');input.value=value;input.style.position='fixed';input.style.opacity='0';document.body.appendChild(input);input.select();document.execCommand('copy');input.remove()}
function feedback(button,label){const original=button.getAttribute('aria-label');button.setAttribute('aria-label',label);button.title=label;setTimeout(()=>{button.setAttribute('aria-label',original);button.title=original},1600)}
copyButton?.addEventListener('click',async()=>{await copy(appUrl);feedback(copyButton,'Endereço copiado')});
shareButton?.addEventListener('click',async()=>{if(navigator.share){await navigator.share({title:document.title,text:'Acesse '+projectName+':',url:appUrl});return}await copy(appUrl);feedback(shareButton,'Endereço copiado')});
{$this->pairingScript($isAuthenticated && $pairingEnabled, $json($csrfToken), $json($pagePath), $json($target))}
</script>
</body></html>
HTML;

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * @param  callable(string): string  $escape
     */
    private function pairingMarkup(bool $isAuthenticated, bool $pairingEnabled, callable $escape, string $loginUrl): string
    {
        if (! $pairingEnabled) {
            return '<div class="pairing-panel"><div class="pairing-copy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 2.9 8.3 7 10 4.1-1.7 7-5.5 7-10V6l-7-3Z"/><path d="M12 8v4M12 16h.01"/></svg><div><h3>Pareamento desativado</h3><p>O acesso rápido está desativado nesta instância do LAN Share.</p></div></div></div>';
        }

        if (! $isAuthenticated) {
            return '<div class="pairing-panel"><div class="guest"><p>Entre neste computador para liberar o acesso rápido e gerar um QR Code temporário.</p><a class="button button-ghost" href="'.$escape($loginUrl).'">Entrar <span aria-hidden="true">↗</span></a></div></div>';
        }

        return <<<'HTML'
<div class="pairing-panel" id="pairing-panel">
<div id="pairing-locked"><div class="pairing-copy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="16" height="11" x="4" y="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg><div><h3>Autorize um novo dispositivo</h3><p>Confirme sua senha para liberar um QR Code que expira rapidamente e só pode ser usado uma vez.</p></div></div><form class="pairing-form" id="pairing-form"><label class="field-label" for="pairing-password">Sua senha</label><div class="input-row"><input class="input" id="pairing-password" name="password" type="password" autocomplete="current-password" placeholder="Digite sua senha" required><button class="button button-primary" type="submit" id="pairing-submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="16" height="11" x="4" y="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Gerar QR Code</button></div><p class="helper">A senha é validada somente nesta sessão e nunca é armazenada.</p><p class="pairing-error" id="pairing-error"></p></form></div>
<div class="pairing-active hidden" id="pairing-active"><div class="qr-shell" id="qr-shell"><div id="qr-surface"></div><div class="qr-overlay" id="qr-overlay" hidden><div class="qr-overlay-inner"><span class="qr-overlay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 2.9 8.3 7 10 4.1-1.7 7-5.5 7-10V6l-7-3Z"/><path d="M12 8v4M12 16h.01"/></svg></span><span id="qr-overlay-text">QR Code encerrado</span></div></div></div><div class="active-meta"><span class="active-status"><span class="active-status-dot"></span><span id="pairing-status">Aguardando leitura</span></span><p class="active-help" id="pairing-help">Abra a câmera do celular e aponte para este código.</p><p class="timer" id="pairing-timer"></p></div><button class="button button-reset" id="pairing-reset" type="button">Gerar outro QR Code</button></div>
</div>
HTML;
    }

    private function pairingScript(bool $enabled, string $csrfToken, string $pagePath, string $target): string
    {
        if (! $enabled) {
            return '';
        }

        return <<<JS
const pairingForm=document.querySelector('#pairing-form'),pairingSubmit=document.querySelector('#pairing-submit'),pairingPassword=document.querySelector('#pairing-password'),pairingError=document.querySelector('#pairing-error'),pairingLocked=document.querySelector('#pairing-locked'),pairingActive=document.querySelector('#pairing-active'),qrShell=document.querySelector('#qr-shell'),qrSurface=document.querySelector('#qr-surface'),qrOverlay=document.querySelector('#qr-overlay'),qrOverlayText=document.querySelector('#qr-overlay-text'),pairingStatus=document.querySelector('#pairing-status'),pairingHelp=document.querySelector('#pairing-help'),pairingTimer=document.querySelector('#pairing-timer'),pairingReset=document.querySelector('#pairing-reset'),pairingTarget={$target};
let pairingId=null,pollTimer=null,countdownTimer=null;
function showPairingError(message){pairingError.textContent=message;pairingError.classList.add('is-visible')}
function clearPairingError(){pairingError.textContent='';pairingError.classList.remove('is-visible')}
function stopPairingTimers(){if(pollTimer){clearInterval(pollTimer);pollTimer=null}if(countdownTimer){clearInterval(countdownTimer);countdownTimer=null}}
async function resetPairing(){const previousPairingId=pairingId;stopPairingTimers();if(previousPairingId){await fetch({$pagePath}+'/pairing/'+previousPairingId,{method:'DELETE',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':{$csrfToken}}}).catch(()=>{})}pairingId=null;pairingActive.classList.add('hidden');pairingLocked.classList.remove('hidden');qrSurface.innerHTML='';qrShell.classList.remove('is-blurred');qrOverlay.hidden=true;pairingPassword.value='';pairingPassword.focus();clearPairingError()}
function finishPairing(state){stopPairingTimers();qrShell.classList.add('is-blurred');qrOverlay.hidden=false;qrOverlayText.textContent=state==='consumed'?'Dispositivo conectado':'QR Code expirado';pairingStatus.textContent=state==='consumed'?'Dispositivo conectado':'QR Code expirado';pairingHelp.textContent=state==='consumed'?'O acesso foi liberado com sucesso. Este código não pode mais ser utilizado.':'Gere um novo código para tentar novamente.';pairingTimer.textContent='';pairingReset.textContent='Gerar QR Code'}
function updateCountdown(expiresAt){const tick=()=>{const remaining=Math.max(0,expiresAt-Math.floor(Date.now()/1000));pairingTimer.textContent=remaining>0?'Expira em '+remaining+'s':'Expirando…';if(remaining<=0){finishPairing('expired')}};tick();countdownTimer=setInterval(tick,1000)}
async function pollPairing(){if(!pairingId)return;try{const response=await fetch({$pagePath}+'/pairing/'+pairingId+'/status',{headers:{Accept:'application/json'},credentials:'same-origin'});if(!response.ok)return;const payload=await response.json();if(payload.state==='consumed'||payload.state==='expired')finishPairing(payload.state)}catch(exception){}}
pairingForm?.addEventListener('submit',async(event)=>{event.preventDefault();clearPairingError();pairingSubmit.disabled=true;pairingSubmit.innerHTML='<span aria-hidden="true">…</span>Gerando';try{const pairingPayload={password:pairingPassword.value};if(pairingTarget!==null)pairingPayload.url=pairingTarget;const response=await fetch({$pagePath}+'/pairing',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':{$csrfToken}},body:JSON.stringify(pairingPayload)});const payload=await response.json().catch(()=>({}));if(!response.ok)throw new Error(payload.message||'Não foi possível gerar o QR Code.');pairingId=payload.pairing_id;qrSurface.innerHTML=payload.qr_svg;qrShell.classList.remove('is-blurred');qrOverlay.hidden=true;pairingLocked.classList.add('hidden');pairingActive.classList.remove('hidden');pairingStatus.textContent='Aguardando leitura';pairingHelp.textContent='Abra a câmera do celular e aponte para este código.';pairingReset.textContent='Cancelar e gerar outro';updateCountdown(payload.expires_at);pollTimer=setInterval(pollPairing,1500)}catch(exception){showPairingError(exception.message)}finally{pairingSubmit.disabled=false;pairingSubmit.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="16" height="11" x="4" y="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Gerar QR Code'}});
pairingReset?.addEventListener('click',resetPairing);
JS;
    }

    private function applicationUrl(Request $request): string
    {
        $configuredUrl = trim((string) config('app.url', ''));

        return rtrim($configuredUrl !== '' ? $configuredUrl : $request->getSchemeAndHttpHost(), '/');
    }

    private function requestTarget(Request $request): ?string
    {
        $value = $request->query('url');

        if (! is_string($value)) {
            return null;
        }

        try {
            return $this->links->normalizeTarget($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
