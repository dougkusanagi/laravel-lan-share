# Planejamento: execução nativa no Windows

> Funcionalidade planejada. O pacote atualmente pressupõe Laravel e Vite no WSL.

## Objetivo e escopo

Suportar projetos executados pelo PHP e Node nativos do Windows, incluindo ambientes baseados em Herd, Laragon ou ferramentas equivalentes.

A detecção considerará o ambiente do processo PHP, não apenas a localização da pasta: um projeto em `C:\` também pode ser iniciado pelo WSL em `/mnt/c`.

O primeiro escopo será o ambiente iniciado e supervisionado pelo próprio `lan:share`. A combinação de projeto WSL com Artisan executado pelo PHP nativo do Windows ficará fora do escopo inicial; diante de uma combinação ambígua, o comando orientará o usuário a usar o PHP do mesmo ambiente do projeto.

## Plano por runtime

O runtime será explícito:

```php
enum RuntimeMode
{
    case Wsl;
    case NativeWindows;
}
```

Quando `PHP_OS_FAMILY === 'Windows'`, o plano selecionará `native-windows` e o agente receberá a necessidade de proxy sem inferi-la a partir de portas ou caminhos:

```json
{
    "runtimeMode": "native-windows",
    "requiresPortProxy": false
}
```

## Comportamento no Windows

O modo nativo deverá:

- detectar o endereço LAN diretamente no Windows;
- procurar portas disponíveis no Windows;
- iniciar Laravel e Vite em `0.0.0.0`;
- criar somente as regras de Firewall necessárias;
- não executar `wsl.exe` ou procurar uma distribuição;
- não descobrir IP interno do WSL;
- não criar `portproxy`;
- manter sessões e heartbeats para remover as regras ao encerrar;
- usar comandos e escaping compatíveis com Windows;
- encerrar toda a árvore dos processos que iniciou;
- procurar executáveis Node como `node_modules\.bin\concurrently.cmd`;
- substituir mensagens específicas de WSL conforme o runtime.

O agente continuará relevante para operações elevadas, Firewall, estado, heartbeat, limpeza e diagnóstico. As regras serão limitadas ao perfil privado e à sub-rede local sempre que possível.

## Servidores já existentes

O suporte a processos previamente iniciados por Herd, Laragon, IIS, Apache ou Nginx será uma etapa posterior e explícita:

```bash
php artisan lan:share --attach=http://127.0.0.1:8000
```

Esse modo deverá:

1. Confirmar que o destino pertence ao projeto.
2. Detectar endereço, porta e protocolo.
3. Verificar se o servidor escuta em interface acessível.
4. Nunca encerrar um processo que não tenha sido iniciado pelo pacote.
5. Remover somente regras ou proxies criados pela sessão.

Abrir o Firewall não torna um listener restrito a `127.0.0.1` acessível pela rede. Nessa situação, o pacote deverá orientar a reconfiguração do servidor ou usar um proxy explicitamente gerenciado.

## Fases de implementação

### Fase 1 — abstração de runtime

- Criar o enum e o resolvedor de ambiente.
- Tornar `wslDistro` e `wslIp` opcionais conforme o runtime.
- Separar planejamento de rede de execução de processos.
- Atualizar mensagens e saída JSON.

### Fase 2 — processo nativo gerenciado

- Criar comandos Windows para Laravel e Vite.
- Resolver `.cmd`, quoting, espaços e Unicode.
- Implementar encerramento seguro da árvore de processos.
- Adicionar preparação do agente sem `portproxy`.
- Criar e limpar apenas regras de Firewall do projeto.

### Fase 3 — diagnóstico e autostart

- Expandir `status` e `doctor` para Windows nativo.
- Integrar com o planejamento de [inicialização automática](autostart.md).
- Validar múltiplos projetos e conflitos de porta.

### Fase 4 — anexação

- Definir protocolo de verificação da aplicação.
- Implementar `--attach` sem assumir propriedade do processo.
- Avaliar HTTP, HTTPS, domínios `.test` e certificados locais.

## Estratégia de testes

A suíte deverá cobrir:

- caminhos com espaços e caracteres não ASCII;
- executáveis `.cmd`;
- ausência das extensões `posix_*`;
- escaping de PowerShell e `cmd.exe`;
- criação e remoção exclusiva das regras de Firewall;
- término da árvore de processos;
- coexistência de múltiplos projetos nativos;
- garantia de que nenhuma chamada a `wsl.exe` ocorre no modo nativo;
- listeners em `0.0.0.0`, IP LAN e `127.0.0.1`.

## Critérios de aceite do MVP

1. `php artisan lan:share` detecta o PHP nativo do Windows sem configuração manual.
2. Laravel e Vite ficam acessíveis na LAN sem criar `portproxy`.
3. Nenhum comando WSL é executado nesse modo.
4. Encerrar o comando remove processos e regras pertencentes à sessão.
5. Caminhos Windows comuns e executáveis `.cmd` funcionam corretamente.
6. O diagnóstico explica portas ocupadas, Firewall bloqueado e dependências ausentes.
