# 🏀 NBA Sim

Simulador de temporada da NBA no estilo **2K MyNBA**, feito em **PHP + MySQL/SQLite + HTML/CSS/JS**.
Sem jogabilidade manual: tudo é **simulado**, com **simcast ao vivo** (narração lance a lance),
box score, classificação, líderes de estatística, histórico de jogos, **Cap por OVR**, **Play-In** e playoffs.

## Recursos
- 30 times reais da NBA (2 conferências de 15, divisões, cores).
- Elencos com estrelas reais + atributos estilo 2K (interior, 3pts, armação, defesa, estamina...).
- Calendário de **82 jogos** por equipe (método do círculo).
- **Sistema de Cap por OVR** — "Regra dos 8 Maiores": teto = soma dos 8 maiores OVRs; janela de ± 15 da média da liga; recálculo a cada 2 temporadas.
- **Motor de simulação por posses** → placar, box score por jogador e play-by-play.
- **Simcast ao vivo**: assista ao jogo lance a lance com placar animado e velocidade ajustável.
- Classificação por conferência (seeds 1–15, vagas diretas, play-in).
- **Torneio Play-In** (7º ao 10º) antes dos playoffs.
- **Playoffs** completos (8 por conferência, séries melhor de 7, até o título).
- Líderes de pontos, rebotes, assistências, roubos e tocos.
- Página de jogador (médias, atributos, histórico) e de time (elenco + cap + esquemas).
- Funciona em **MySQL** (Hostinger) ou **SQLite** (teste local), via `config/config.php`.

## Como rodar
1. Dê um duplo clique em **`start.bat`** (usa o PHP do XAMPP em `C:\xampp`).
2. Abra **http://localhost:8000** no navegador.
3. **Crie sua conta** e faça login.
4. Em **Meus Saves**, crie um save: dê um nome, **crie seu GM** e **escolha a franquia**. (Até **2 saves** por conta.)
5. Comande tudo: **Avançar data** segue o calendário e para no seu jogo para você **comandar ao vivo** ou simular.

## Contas e Saves (multi-save)
- Cada usuário tem **login/senha** (hash bcrypt) e até **2 saves** independentes.
- Cada save é um **banco SQLite isolado** em `storage/saves/save_{id}.sqlite`; as contas ficam em `storage/accounts.sqlite`.
- Tudo persiste automaticamente — é só **Carregar** o save em "Meus Saves" para continuar de onde parou.

> Alternativa por linha de comando:
> ```
> cd public
> C:\xampp\php\php.exe -S localhost:8000
> ```

## Banco de dados (MySQL / SQLite)
A conexão é definida em **`config/config.php`** (`driver` = `mysql` ou `sqlite`).
Em desenvolvimento existe `config/config.local.php`, que sobrescreve o config para o
MySQL do XAMPP local (host `127.0.0.1`) — **não suba esse arquivo para produção**.

### Publicar na Hostinger
1. Faça upload da pasta do projeto para a Hostinger (de preferência a pasta `public/` como raiz do site, ou ajuste o domínio para apontar para ela).
2. **Não** envie `config/config.local.php`.
3. Em `config/config.php`, deixe `driver => 'mysql'` e `host => 'localhost'` (quando o jogo roda na própria Hostinger).
4. Acesse o site e clique em **Iniciar nova liga** — o schema e os dados são criados automaticamente no banco `u289267434_fbagame`.

> Para rodar o jogo no seu PC conectando ao banco da Hostinger (acesso remoto):
> ative **Remote MySQL** no hPanel, libere o seu IP público e use o host informado lá
> (algo como `srvXXX.hstgr.io`) em `config/config.php`.

## Estrutura
```
fbagame/
├─ public/            # raiz web (index.php, api.php, assets)
│  ├─ index.php       # front controller / rotas
│  ├─ api.php         # JSON do jogo (simcast)
│  └─ assets/         # css + js (simcast.js anima o jogo)
├─ src/
│  ├─ Database.php    # conexão SQLite + schema
│  ├─ Installer.php   # popula times/jogadores + calendário
│  ├─ SimEngine.php   # motor de simulação (posses)
│  ├─ League.php      # classificação, stats, playoffs
│  ├─ helpers.php     # layout/helpers
│  └─ views/          # páginas
├─ data/
│  ├─ teams.php       # 30 times
│  └─ players.php     # elencos (edite aqui para ajustar ratings)
└─ storage/game.sqlite  # banco gerado (ignorável)
```

## Editar elencos / ratings
Edite `data/players.php` (núcleo real de cada time) e reinstale a liga em **Início → Iniciar nova liga**.
O instalador completa cada elenco até 13 jogadores com role players gerados.

## Ciclo MyNBA (multi-temporada)
Ao fim dos playoffs o jogo entra em **Off-season**. Em "Início" use **Iniciar próxima temporada** para rodar a entressafra:
- **Prêmios**: MVP, DPOY, Novato do Ano (ROY), MVP das Finais e Quintetos All-NBA (1º/2º/3º).
- **Progressão/Declínio**: jovens (19–24) sobem rumo ao **potencial oculto**; auge (25–31) estável; veteranos (32+) caem e podem **se aposentar**.
- **Draft** com **névoa de guerra**: calouros chegam com OVR baixo, notas de olheiro e **potencial oculto** (estrelas e *busts*); pior campanha escolhe primeiro.
- **Trocas** + **adequação obrigatória de Cap** no fim de ciclo (a cada 2 temporadas): time estourado manda estrela e recebe coadjuvante.
- **Histórico**: campeões, dinastias (títulos), prêmios por temporada e recordes.

## Sistemas de jogo (SimCast)
- **Comando ao vivo (modo GM)**: ao abrir o jogo do seu time, você o comanda **período a período**. A cada
  parcial pode trocar o **foco ofensivo** e o **esquema defensivo**, ordenar **marcação dupla** na estrela
  adversária e **pedir tempo** (alívio de cansaço + leve bônus). No 4º quarto apertado o painel sinaliza o
  **Clutch Time**. Botões: *Comandar ao vivo* (período a período), *Jogar até o fim (auto)* e *Simular auto*.
- **Lesões**: titulares podem se lesionar (3–20 jogos) conforme minutos, idade e estamina — o reserva assume.
- **Estamina**: queda de rendimento no fim de jogos apertados para quem tem pouca resistência.
- **Esquemas**: ofensivo (Pace and Space, Pick and Roll, Post Play) e defensivo (Man-to-Man, 2-3 Zone, Switch All).
- **Moral e Química**: jogador mal aproveitado rende menos; sequência de vitórias eleva a química do time.

## Modo GM (franquia controlada)
- **Temporada jogo a jogo (pelo calendário)**: cada dia mostra a **data** (out → abr). Ao clicar em
  **Avançar data**, os outros jogos do dia são simulados e o calendário **para no jogo do seu time** —
  você decide *comandar ao vivo* ou *simular*. Depois é só **Continuar temporada** para a próxima data.
  O painel **Início** destaca o "Seu jogo de hoje".
- **Inbox do GM** e **confiança da diretoria** (barra 0–100, da "cadeira quente" à "sólida") em *Meu Time*.
- **Decisões com escolhas**: ao avançar as datas surgem decisões (ex.: jogador insatisfeito **pede troca** →
  prometer minutos / colocar na vitrine / ignorar; **poupar veterano** por 2 jogos) — cada escolha tem efeito real
  (moral, descanso, vitrine de trocas).
- **Mini-calendário** da sua franquia (próximos jogos com data) e **scouting do adversário** antes de cada jogo:
  forças/fraquezas, jogadores-chave, forma recente, **confronto direto na temporada (H2H)** e plano tático sugerido.
- **Histórico de temporadas**: em *Histórico*, a **trajetória da sua franquia** (campanha, seed e resultado de
  playoffs ano a ano), títulos/finais/presenças, além de campeões, prêmios e recordes de todas as temporadas.
- **Metas da diretoria** por temporada (playoffs / Finais / título / nº de vitórias) com acompanhamento.
- **Free Agency**: após o Draft abre a janela de agentes livres. Você disputa contratações com a IA
  (que assina por necessidade posicional); ao concluir, a IA completa os elencos e a temporada começa.
- **Esquema, rotação e trocas** definidos manualmente; o resto da liga é simulado pela IA.

## Roadmap (próximos)
- Substituições manuais ao vivo durante o período.
- Free agency com propostas de múltiplos anos / disputa por OVR.
- Scouting do próximo adversário antes do jogo.

> Projeto não oficial. Dados de jogadores são aproximações para fins de simulação.
