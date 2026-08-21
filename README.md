# Laravel LAN Share

Compartilha um ambiente Laravel/Vite executado no WSL com outros dispositivos da rede local.

## Uso recomendado

```bash
php artisan lan:install
php artisan lan:share
```

`lan:install` instala ou atualiza antecipadamente os scripts do agente em `%LOCALAPPDATA%`. Se o agente estiver ausente ou desatualizado, `lan:share` oferece a instalação automaticamente antes de continuar. Em automações ou terminais não interativos, use `php artisan lan:share --install`.

Durante a instalação, o pacote cria `vite.lan.config.ts` como um wrapper do `vite.config.ts` do projeto. Esse wrapper mantém os plugins existentes e usa `VITE_DEV_ORIGIN` para anunciar ao Laravel o endereço LAN do Vite, enquanto o servidor continua escutando em `0.0.0.0`. Se o projeto já usa `vite.config.lan.ts`, esse arquivo é mantido e usado automaticamente.

Depois da instalação, `lan:share` inicia o agente Windows, solicita a confirmação do UAC, configura `portproxy`/Firewall e envia heartbeats enquanto Laravel e Vite estiverem rodando. A instalação fica fora do caminho crítico das inicializações seguintes.

O `portproxy` escuta em todas as interfaces IPv4 do Windows. Assim, a mesma sessão fica acessível pelo IP LAN em outros dispositivos e também pelo próprio Windows, usando o IP LAN ou `localhost`.

Para iniciar o Vite, `lan:share` prioriza Bun (`bun run dev`), depois tenta pnpm e npm como fallback.

O QR Code da URL da aplicação é exibido por padrão para abrir o projeto no celular. Para ocultá-lo em uma execução:

```bash
php artisan lan:share --no-qr
```

Para fazer com que os dispositivos já abram uma página específica, informe o caminho como argumento. O destino é aplicado ao QR Code do terminal, aos links de compartilhamento e à página local:

```bash
php artisan lan:share /dashboard
php artisan lan:share '/pedidos/42?aba=historico'
# Forma equivalente:
php artisan lan:share --url=/dashboard
```

O terminal também mostra a disponibilidade na rede, uma URL `wa.me` clicável e uma página local em `/__lan-share` com QR Code, copiar, compartilhamento nativo (quando disponível) e WhatsApp. Quando um destino é informado, o link da página local recebe `?url=...`, por exemplo `/__lan-share?url=%2Fdashboard`. Use `--no-share-links` para ocultar esses links.

Uma nova execução também encerra automaticamente processos antigos do LAN Share pertencentes ao mesmo projeto. Use `--no-replace` somente quando quiser preservar uma execução paralela.

```bash
php artisan lan:agent status
php artisan lan:agent doctor
php artisan lan:share:cleanup
php artisan lan:uninstall
```

Os ciclos de vida são independentes: `lan:share:cleanup` remove somente sessões, regras de Firewall e `portproxy`; `lan:uninstall` encerra sessões remanescentes com segurança e remove somente o runtime versionado do agente.

O agente expira leases abandonados e remove os recursos associados ao projeto quando o processo termina inesperadamente. Para forçar o fluxo compatível baseado em script:

```bash
php artisan lan:share --legacy
php artisan lan:share:cleanup --legacy
```

Para desativar o agente por configuração:

```dotenv
LAN_SHARE_AGENT=false
LAN_SHARE_QR=false
LAN_SHARE_REPLACE_EXISTING=false
```

O agente atual é PowerShell por ser o caminho sem compilação adicional no MVP. O protocolo JSON/Named Pipe foi mantido independente para permitir uma futura implementação em .NET sem alterar os comandos Artisan.

## Implementações futuras

As propostas abaixo ainda não estão disponíveis e possuem documentos próprios para manter este README focado no uso atual:

- [Inicialização automática e acesso permanente na LAN](docs/autostart.md)
- [Execução nativa no Windows](docs/native-windows.md)
- [Túnel público temporário](docs/public-tunnel.md)
