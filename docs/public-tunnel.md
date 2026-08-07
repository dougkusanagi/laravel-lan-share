# Planejamento: túnel público temporário

> Funcionalidade planejada. Ela publicará a aplicação na internet e terá um modelo de segurança diferente do compartilhamento restrito à LAN.

## Objetivo

Permitir demonstrações e testes externos por uma URL HTTPS temporária, sem redirecionamento de portas no roteador, IP público ou configuração manual de DNS.

O MVP será destinado a uma aplicação com assets compilados. Ele não iniciará Vite e não oferecerá HMR: quem publica temporariamente um sistema na internet precisa compartilhar a aplicação executável, não o ambiente de desenvolvimento frontend.

## Distinção entre DNS e túnel

Serviços como [sslip.io](https://sslip.io/) apenas resolvem um hostname com um IP embutido para esse endereço. Eles são úteis para nomes previsíveis na LAN, mas não encaminham tráfego, não atravessam NAT e não substituem um túnel.

O primeiro provider proposto é o [Cloudflare Quick Tunnel](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/do-more-with-tunnels/trycloudflare/), que cria uma conexão de saída e fornece uma URL aleatória em `trycloudflare.com` sem exigir conta. Quick Tunnels são voltados a testes, não possuem SLA e têm limitações documentadas pelo provedor.

## Experiência pretendida

O túnel será um comando separado para tornar explícito que a aplicação ficará pública:

```bash
php artisan lan:tunnel
php artisan lan:tunnel --no-build
php artisan lan:tunnel --yes
```

O fluxo padrão deverá:

1. Exibir o aviso de exposição pública e solicitar confirmação.
2. Validar PHP, Node, npm, `cloudflared` e o estado do projeto.
3. Executar o comando configurado de build dos assets.
4. Confirmar a presença de `public/build/manifest.json` ou equivalente.
5. Selecionar uma porta local livre.
6. Iniciar somente a aplicação Laravel em loopback.
7. Iniciar o provider apontando para essa origem.
8. Capturar e validar a URL HTTPS temporária.
9. Exibir URL, QR Code e ações de copiar/compartilhar.
10. Monitorar Laravel e o processo do túnel.
11. Encerrar ambos e limpar o estado quando qualquer processo terminar ou o usuário pressionar `Ctrl+C`.

`--no-build` permitirá reutilizar assets existentes, mas falhará com uma explicação clara se o manifesto não existir. `--yes` servirá para automação consciente e não desabilitará validações de segurança obrigatórias.

## Arquitetura

O conector será executado no mesmo runtime que o Laravel sempre que possível:

```text
Internet
   ↓ HTTPS
Provider do túnel
   ↓ conexão de saída
cloudflared no mesmo runtime
   ↓ http://127.0.0.1:<porta>
Laravel + assets compilados
```

No WSL, `cloudflared` será iniciado dentro da distribuição e apontará para o loopback do WSL. No Windows nativo, será iniciado diretamente no Windows. Dessa forma, o modo túnel não precisará criar `portproxy`, liberar portas de entrada no Firewall ou descobrir o IP LAN.

O pacote deverá abstrair o provedor:

```php
interface TunnelProvider
{
    public function availability(): TunnelAvailability;

    public function start(TunnelPlan $plan): TunnelSession;

    public function stop(TunnelSession $session): void;
}
```

O protocolo interno não deverá depender de textos específicos impressos no terminal além do adapter do provider. Estado, URL, PID, versão e capacidades serão normalizados em `TunnelSession`.

## Cloudflare Quick Tunnel

O primeiro adapter executará conceitualmente:

```bash
cloudflared tunnel --url http://127.0.0.1:8080
```

Responsabilidades do adapter:

- localizar e verificar a versão de `cloudflared`;
- iniciar o processo sem shell sempre que possível;
- capturar a URL `https://*.trycloudflare.com` de maneira limitada e validada;
- diferenciar falha de conexão, rate limit, binário incompatível e encerramento normal;
- manter logs do provider separados dos logs da aplicação;
- terminar o processo e seus descendentes ao encerrar a sessão.

O MVP poderá exigir `cloudflared` previamente instalado. Uma instalação automatizada posterior deverá pedir consentimento, usar fonte oficial, verificar integridade e nunca executar silenciosamente um download não validado.

## Ambiente Laravel

Laravel será iniciado com variáveis apenas para o processo, sem reescrever `.env`. O plano deverá considerar:

- `APP_URL` igual à URL pública depois que ela for conhecida;
- origem HTTPS e geração de URLs seguras;
- cookies `Secure` quando aplicável;
- proxies confiáveis e leitura correta do protocolo encaminhado;
- host público aceito pela aplicação;
- limpeza de caches que tenham congelado valores incompatíveis, somente quando autorizada.

Como a URL só é conhecida depois que `cloudflared` inicia, o runner poderá primeiro reservar a porta e subir uma origem de espera, obter a URL e então iniciar Laravel com o ambiente definitivo. Outra opção será reiniciar Laravel uma única vez antes de anunciar a URL. A implementação deverá escolher uma sequência determinística e sem janela pública para uma aplicação configurada incorretamente.

## Segurança

Uma URL aleatória não será tratada como autenticação. Antes de publicar, o comando deverá:

- informar claramente que qualquer pessoa com a URL poderá tentar acessar a aplicação;
- recusar `APP_DEBUG=true` por padrão, aceitando exceção somente com uma opção explícita como `--allow-debug`;
- alertar sobre Telescope, Horizon, Pulse, painéis administrativos e rotas sem autenticação;
- verificar se o ambiente contém configuração obviamente incompatível com exposição pública;
- não mostrar valores do `.env`, tokens ou cabeçalhos sensíveis nos logs;
- não persistir a URL temporária como configuração permanente;
- manter o servidor local ligado somente em loopback;
- encerrar o túnel se Laravel ficar indisponível;
- registrar horário de início, provider e URL, sem registrar dados trafegados.

Autenticação continua sendo responsabilidade da aplicação. Um modo futuro com túnel nomeado poderá integrar Cloudflare Access, mas exigirá conta, domínio e credenciais e não fará parte do Quick Tunnel MVP.

## Configuração proposta

```php
'tunnel' => [
    'provider' => 'cloudflare-quick',
    'build_command' => 'npm run build',
    'require_build' => true,
    'allow_debug' => false,
    'startup_timeout' => 30,
    'log' => storage_path('logs/lan-share-tunnel.log'),
],
```

Possíveis variáveis:

```dotenv
LAN_SHARE_TUNNEL_PROVIDER=cloudflare-quick
LAN_SHARE_TUNNEL_BUILD_COMMAND="npm run build"
LAN_SHARE_TUNNEL_ALLOW_DEBUG=false
```

## Fases de implementação

### Fase 1 — plano e ciclo de vida local

- Criar `TunnelPlan`, `TunnelSession` e `TunnelProvider`.
- Adicionar o comando `lan:tunnel` e confirmação de exposição pública.
- Implementar build e validação do manifesto.
- Iniciar Laravel somente em loopback, sem Vite.
- Implementar sinais, lock e encerramento da árvore de processos.

### Fase 2 — Quick Tunnel

- Implementar o adapter de `cloudflared`.
- Capturar e validar a URL temporária.
- Integrar QR Code, clipboard, página de compartilhamento e WhatsApp.
- Adicionar logs e mensagens de diagnóstico específicas do provider.

### Fase 3 — segurança e robustez

- Implementar verificações de debug e superfícies administrativas conhecidas.
- Ajustar ambiente Laravel para HTTPS e proxies.
- Tratar queda e reinício dos processos sem deixar túnel órfão.
- Adicionar `lan:agent doctor` ou `lan:tunnel doctor`.

### Fase 4 — providers autenticados

- Avaliar túnel nomeado com URL estável.
- Avaliar Cloudflare Access e políticas de identidade.
- Definir armazenamento seguro de credenciais fora do projeto.
- Permitir providers adicionais sem acoplá-los ao comando.

## Estratégia de testes

Testes unitários e de integração deverão cobrir:

- build bem-sucedido, ausente e com falha;
- garantia de que Vite nunca é iniciado;
- Laravel restrito ao loopback;
- parsing e validação da URL do provider;
- timeouts, desconexão e códigos de saída;
- encerramento de Laravel quando o túnel cai e vice-versa;
- nenhum `portproxy` ou regra de entrada no Firewall;
- ambientes WSL e Windows nativo;
- recusa de debug e confirmação obrigatória;
- ausência de segredos nos logs;
- adapters falsos para evitar túneis públicos durante a suíte comum.

Testes reais com o provider ficarão em uma suíte opt-in para não depender de rede externa, limites ou disponibilidade do serviço em cada execução de CI.

## Critérios de aceite do MVP

1. `lan:tunnel` compila ou valida os assets e nunca inicia Vite.
2. Uma URL HTTPS temporária funcional é exibida sem configurar roteador, DNS ou Firewall de entrada.
3. Laravel fica acessível somente por loopback local e pelo túnel.
4. `Ctrl+C`, falha de Laravel ou falha do provider encerram toda a sessão sem processos órfãos.
5. Debug é recusado por padrão e a exposição pública exige confirmação inequívoca.
6. Nenhum segredo é persistido ou exibido nos logs.
7. O fluxo funciona no WSL e possui contrato compatível com o futuro modo Windows nativo.
8. Falhas de instalação, rede e provider produzem diagnóstico acionável.
