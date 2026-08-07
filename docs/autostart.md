# Planejamento: inicialização automática

> Funcionalidade planejada. Os comandos `lan:autostart` ainda não estão disponíveis.

## Objetivo

Permitir que um projeto volte a ficar acessível na rede local depois que o Windows for reiniciado, sem exigir que o usuário abra um terminal WSL e execute `php artisan lan:share` manualmente.

A primeira implementação usará o Agendador de Tarefas do Windows como ponto de entrada, pois uma distribuição WSL e seus serviços internos não são necessariamente iniciados durante o boot do Windows.

## Experiência pretendida

```bash
php artisan lan:autostart install
php artisan lan:autostart status
php artisan lan:autostart logs
php artisan lan:autostart restart
php artisan lan:autostart disable
php artisan lan:autostart enable
php artisan lan:autostart uninstall
```

O comando `install` deverá:

1. Validar o projeto, a distribuição WSL, os executáveis PHP/Node e as dependências necessárias.
2. Instalar ou atualizar o agente Windows antes de registrar a inicialização automática.
3. Registrar uma tarefa do Windows específica para o projeto, sem conflitar com outros projetos.
4. Executar a tarefa após o login do usuário, com um pequeno atraso para aguardar a rede.
5. Iniciar a distribuição correta, entrar no diretório Linux do projeto e executar o compartilhamento em modo não interativo.
6. Configurar novas tentativas com atraso progressivo caso o processo termine inesperadamente.
7. Exibir a tarefa criada, o modo, a distribuição, as portas preferenciais e o local dos logs.

A tarefa executará conceitualmente:

```powershell
wsl.exe -d <distribuicao> --cd <diretorio-linux> `
    bash -lc "php artisan lan:share --autostart"
```

O modo `lan:share --autostart` será não interativo, omitirá QR Code e links de compartilhamento, evitará prompts de instalação e produzirá logs adequados para segundo plano. Ele continuará usando sessões, heartbeat, substituição de processos antigos e limpeza de recursos.

## Modos de execução

```bash
php artisan lan:autostart install --mode=dev
php artisan lan:autostart install --mode=app
```

- `dev`: inicia Laravel e Vite, preservando hot reload para uso restrito à rede local.
- `app`: exige assets compilados e inicia somente Laravel; será o modo recomendado para maior estabilidade e menor consumo.

O modo `app` não transforma `artisan serve` em solução de produção. Uma integração futura com Caddy ou Nginx/PHP-FPM será avaliada separadamente.

## Estado e identificação

Cada projeto terá uma identidade estável derivada do caminho canônico, reaproveitando o `stateKey`. O estado registrará:

- versão do formato;
- identificador e nome da tarefa do Windows;
- caminho Windows/WSL e distribuição;
- modo de execução;
- portas preferenciais de Laravel e Vite;
- data da instalação e última atualização;
- caminho do log;
- estado habilitado/desabilitado.

Isso permitirá atualizar uma tarefa sem criar duplicatas e remover apenas recursos do projeto atual. Se o projeto for movido ou a distribuição deixar de existir, `lan:autostart status` explicará o problema e indicará como reinstalar a configuração.

## Inicialização e supervisão

O runner deverá:

1. Adquirir um lock exclusivo por projeto.
2. Aguardar conectividade e disponibilidade da distribuição WSL.
3. Encerrar somente processos antigos reconhecidos como pertencentes ao projeto.
4. Recalcular os endereços do Windows e do WSL a cada inicialização.
5. Solicitar ao agente a recriação de `portproxy` e regras de Firewall.
6. Iniciar Laravel e, no modo `dev`, Vite.
7. Manter heartbeats enquanto os processos estiverem saudáveis.
8. Encerrar o grupo de processos e liberar a sessão ao receber um sinal de parada.
9. Retornar código de saída útil para a política de reinício da tarefa.

As tentativas usarão backoff e limite configurável para evitar loops intensos diante de erros permanentes.

## Portas, endereço e descoberta

O agente continuará recalculando o IP interno do WSL. A busca automática de portas poderá ser mantida, mas o modo permanente avisará quando a porta publicada mudar.

Como o IP LAN também pode mudar por DHCP, o diagnóstico recomendará uma reserva DHCP quando o usuário precisar de endereço estável. Depois do MVP, poderão ser avaliados:

- hostname do Windows quando resolvível na rede;
- publicação opcional por mDNS, como `meu-projeto.local`;
- página local com os projetos e suas URLs atuais.

## Segurança

A instalação exigirá confirmação explícita e deverá:

- limitar regras de Firewall ao perfil privado;
- restringir o escopo à sub-rede local sempre que possível;
- nunca desabilitar o Firewall ou reutilizar regras externas;
- alertar quando `APP_DEBUG=true` ou houver indícios de dados sensíveis;
- documentar que autenticação e autorização pertencem à aplicação;
- não gravar segredos, conteúdo do `.env` ou tokens no estado e nos logs;
- escapar corretamente nomes, argumentos e caminhos entre PowerShell, `wsl.exe` e Bash.

## Logs e diagnóstico

O runner gravará logs rotativos em `storage/logs/lan-share-autostart.log`. `lan:autostart logs` exibirá linhas recentes e aceitará `--follow`. `lan:autostart status` combinará:

- existência e estado da tarefa;
- última execução e código de saída;
- distribuição e caminho configurados;
- saúde do agente;
- sessão e heartbeat;
- processos Laravel/Vite;
- regras de Firewall e `portproxy` do projeto;
- teste opcional da URL publicada.

`lan:agent doctor` ganhará verificações de autostart, mas continuará funcionando quando a funcionalidade não estiver instalada.

## Configuração proposta

```php
'autostart' => [
    'enabled' => false,
    'mode' => 'app',
    'trigger' => 'logon',
    'startup_delay' => 15,
    'restart_attempts' => 5,
    'restart_delay' => 10,
    'log' => storage_path('logs/lan-share-autostart.log'),
],
```

Os valores instalados serão copiados para o estado da tarefa. Alterações posteriores no `.env` não modificarão silenciosamente uma tarefa existente; `lan:autostart install` ou um futuro `lan:autostart update` aplicará as opções explicitamente.

## Fases de implementação

### Fase 1 — modelo e geração

- Criar objetos de valor para configuração e estado.
- Definir nomes determinísticos e seguros para tarefas.
- Criar renderer PowerShell para gerenciar a tarefa.
- Implementar runner não interativo e lock por projeto.
- Adicionar `lan:share --autostart` sem registrar tarefas.

### Fase 2 — ciclo de vida

- Implementar `install`, `status`, `enable`, `disable` e `uninstall`.
- Solicitar elevação apenas nas operações necessárias.
- Garantir atualização idempotente e remoção restrita ao projeto.
- Integrar a instalação antecipada do agente.

### Fase 3 — supervisão e observabilidade

- Implementar `restart` e `logs --follow`.
- Adicionar política de tentativas e códigos de saída classificados.
- Criar logs rotativos resilientes.
- Expandir `status` e `doctor` com testes ponta a ponta.

### Fase 4 — robustez e descoberta

- Tratar troca de rede, suspensão e alteração do IP do WSL.
- Avaliar hostname/mDNS e URL estável.
- Avaliar inicialização no boot sem login e suas implicações de credenciais.
- Avaliar backend .NET sem alterar o protocolo público.

## Estratégia de testes

Testes unitários cobrirão geração e escaping, nomes de tarefas, serialização, modos, locks e códigos de saída. Testes de integração usarão adapters falsos para o Agendador, `wsl.exe`, agente, processos e relógio.

A matriz Windows/WSL verificará:

- instalação nova e atualização idempotente;
- caminhos com espaços e caracteres não ASCII;
- múltiplas distribuições e projetos;
- reinicialização do Windows e novo login;
- falha do PHP, Node e agente;
- suspensão, retorno e mudança de rede;
- mudança do IP interno do WSL;
- portas ocupadas;
- projeto movido ou removido;
- `vendor` ou `node_modules` ausentes;
- desinstalação sem afetar outros projetos.

## Critérios de aceite do MVP

1. `lan:autostart install` registra a tarefa sem etapas manuais além do UAC necessário.
2. Após reiniciar o Windows e entrar na conta, o projeto responde na LAN sem abrir terminal.
3. Mudanças do IP interno do WSL não invalidam o compartilhamento.
4. Falhas transitórias geram nova tentativa e falhas permanentes ficam diagnosticáveis.
5. Reinstalar não duplica tarefas, processos, regras ou mapeamentos.
6. `disable` interrompe novas inicializações sem perder a configuração.
7. `uninstall` remove somente recursos do projeto atual.
8. `status`, `logs` e `doctor` diagnosticam inicializações malsucedidas.
