---
description: Liga o bot do WhatsApp ignorando a janela de horário (ou desliga com "off")
argument-hint: "[sempre | off | 1..12]"
allowed-tools: Bash(C:/xampp/php/php.exe bot/plantao.php:*)
---

Rode o script do plantão com o argumento `$ARGUMENTS` (vazio = `sempre`):

```bash
C:/xampp/php/php.exe bot/plantao.php sempre
```

Trocando `sempre` pelo que veio em `$ARGUMENTS`, quando vier algo.

O script cuida de tudo: fala com o site pelo token do bot, muda o plantão e
volta o estado. Não precisa de banco nem de navegador logado.

Depois, me diga em uma ou duas linhas o que ele respondeu — principalmente se
alguma das quatro coisas que fazem o bot funcionar não estiver de pé:

- **plantão** — se a janela de horário está valendo ou não
- **bot** — o liga/desliga geral, que fica na Central da Liga
- **worker** — a tarefa `FBA WhatsApp Worker` desta máquina; sem ela nada sai
- **evolution** — o container do WhatsApp; `open` é o estado bom

Se o worker estiver parado, ofereça rodar `bot/ligar-bot.bat`. Se a Evolution
não responder, ofereça subir o container em `bot/evolution`.
