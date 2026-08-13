<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PairingConnectPageController
{
    public function __invoke(Request $request): Response
    {
        $csrfTokenHtml = htmlspecialchars($request->session()->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrfToken = json_encode(
            $request->session()->token(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );

        $html = <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{$csrfTokenHtml}">
<title>Conectando dispositivo</title>
<style>
:root{font-family:"Gill Sans","Avenir Next","Segoe UI",sans-serif;color:#17201f;background:#f4f0e8;font-synthesis:none;text-rendering:optimizeLegibility}
*{box-sizing:border-box}body{margin:0;min-height:100svh;display:grid;place-items:center;overflow:hidden;background:radial-gradient(circle at 8% 12%,#ffd9ce 0,transparent 31%),radial-gradient(circle at 90% 88%,#d8e7df 0,transparent 34%),#f4f0e8}.shell{width:min(100% - 32px,460px);padding:34px 0}.card{border:1px solid #d9ddd6;border-radius:24px;background:rgba(255,253,248,.9);padding:36px 30px;text-align:center;box-shadow:0 24px 80px rgba(38,46,40,.11),0 4px 14px rgba(38,46,40,.04);backdrop-filter:blur(14px)}.mark{display:grid;place-items:center;width:56px;height:56px;margin:0 auto 20px;border-radius:18px;color:#fffdf8;background:#17201f;box-shadow:0 12px 24px rgba(23,32,31,.2)}.mark svg{width:27px;height:27px}.eyebrow{margin:0 0 10px;color:#c94331;font-size:11px;font-weight:750;letter-spacing:.14em;text-transform:uppercase}.title{margin:0;font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;font-size:clamp(23px,6vw,30px);font-weight:500;letter-spacing:-.04em}.description{margin:12px auto 0;max-width:330px;color:#68736f;line-height:1.55;font-size:14px}.loader{width:24px;height:24px;margin:28px auto 0;border:3px solid #ffe3dc;border-top-color:#e4583f;border-radius:999px;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}.error{display:none;margin:24px 0 0;border-radius:12px;padding:12px 14px;color:#a33a2c;background:#fff0eb;font-size:13px;line-height:1.45}.link{display:inline-flex;margin-top:20px;color:#c94331;font-size:13px;font-weight:650;text-decoration:none}.link:hover{text-decoration:underline}
</style>
</head>
<body><main class="shell"><section class="card" aria-live="polite"><div class="mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 2.9 8.3 7 10 4.1-1.7 7-5.5 7-10V6l-7-3Z"/><path d="m9.4 12 1.7 1.7 3.7-3.7"/></svg></div><p class="eyebrow">Acesso seguro</p><h1 class="title" id="title">Conectando dispositivo</h1><p class="description" id="description">Validando seu QR Code e preparando sua sessão.</p><div class="loader" id="loader" role="status" aria-label="Carregando"></div><p class="error" id="error"></p><a class="link" id="home" href="/" hidden>Ir para o início</a></section></main>
<script>
const csrfToken={$csrfToken};
const title=document.querySelector('#title'),description=document.querySelector('#description'),loader=document.querySelector('#loader'),error=document.querySelector('#error'),home=document.querySelector('#home');
function showError(message){loader.style.display='none';title.textContent='Não foi possível conectar';description.textContent='O QR Code pode ter expirado ou já ter sido utilizado.';error.textContent=message;error.style.display='block';home.hidden=false}
const token=new URLSearchParams(location.hash.slice(1)).get('token');
history.replaceState(null,'',location.pathname+location.search);
if(!token){showError('Nenhum token de pareamento foi encontrado. Gere um novo QR Code no computador.')}else{fetch(location.pathname,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrfToken},body:JSON.stringify({token})}).then(async(response)=>{const payload=await response.json().catch(()=>({}));if(!response.ok){throw new Error(payload.message||'Este QR Code não pode mais ser utilizado.')}return payload}).then((payload)=>{title.textContent='Tudo pronto';description.textContent=payload.message||'O dispositivo foi conectado. Você já pode continuar.';loader.style.display='none';setTimeout(()=>{window.location.assign(payload.redirect_url||'/')},650)}).catch((exception)=>showError(exception.message))}
</script>
</body></html>
HTML;

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
