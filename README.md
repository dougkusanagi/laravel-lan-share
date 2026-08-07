# Laravel LAN Share

Compartilha um ambiente Laravel/Vite executado no WSL com outros dispositivos da rede local.

## Uso recomendado

```bash
php artisan lan:install
php artisan lan:share
```

`lan:install` instala ou atualiza antecipadamente os scripts do agente em `%LOCALAPPDATA%`. Se o agente estiver ausente ou desatualizado, `lan:share` oferece a instalação automaticamente antes de continuar. Em automações ou terminais não interativos, use `php artisan lan:share --install`.

Depois da instalação, `lan:share` inicia o agente Windows, solicita a confirmação do UAC, configura `portproxy`/Firewall e envia heartbeats enquanto Laravel e Vite estiverem rodando. A instalação fica fora do caminho crítico das inicializações seguintes.

O QR Code da URL da aplicação é exibido por padrão para abrir o projeto no celular. Para ocultá-lo em uma execução:

```bash
php artisan lan:share --no-qr
```

O terminal também mostra a disponibilidade na rede, uma URL `wa.me` clicável e uma página local em `/__lan-share` com QR Code, copiar, compartilhamento nativo (quando disponível) e WhatsApp. Use `--no-share-links` para ocultar esses links.

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
