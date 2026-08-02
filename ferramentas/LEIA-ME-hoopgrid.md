# Atualização da base de jogadores (hoopgrid_players)

A base foi enriquecida com o dataset aberto do Basketball-Reference
(github.com/sumitrodatta/bball-reference-datasets), que é atualizado a cada
temporada. Para repetir no futuro (ex.: quando a temporada 2026-27 acabar):

1. Baixe os CSVs da pasta `Data/` do repositório acima (branch `master`):
   Player Award Shares, End of Season Teams, All-Star Selections,
   Player Season Info, Player Career Info, Player Per Game.
2. Tenha um MySQL local com uma cópia da tabela `hoopgrid_players`.
3. Rode: `php ferramentas/enriquecer-hoopgrid.php <pasta-dos-csvs> <banco-local>`
4. Ele gera `atualizar-hoopgrid.sql` na pasta dos CSVs — revise o relatório
   (casados / sem match) e importe o SQL na produção pelo phpMyAdmin.

Antes de rodar para uma temporada nova, atualize DENTRO do script:
- `$CAMPEOES` — adicione o campeão da temporada nova (código BBRef do time).
- `$FINALS_MVP` — adicione o Finals MVP (nome como aparece no Basketball-Reference).

## Vocabulário de prêmios gravado em `premios`

MVP, FINALS_MVP, CHAMPION, DPOY, ROY, SIXTHMAN, MIP, CLUTCH, SCORING,
ALLSTAR, ALL_NBA, ALL_DEFENSE, ALL_ROOKIE, HOF

Qualquer jogo novo deve usar essas chaves. O hoopgrid antigo usa um
subconjunto (MVP, CHAMPION, ALLSTAR, DPOY, FINALS_MVP, ROY, SCORING,
SIXTHMAN) — as chaves extras não atrapalham.

## O que o script faz

- Casa jogador do dataset com a base por nome normalizado (acentos, apelidos
  históricos tipo Tiny Archibald, sufixos Jr/III), desempatando por ano de
  nascimento; ~96% de aproveitamento.
- SUBSTITUI `premios` pelo computado (a lista antiga tinha vocabulário legado
  e erros manuais); completa `times` (franquia atual: SEA→OKC, NJN→BKN...),
  `eras`, `titulos` e médias/bio faltantes.
- CHAMPION vem do elenco do time campeão em cada temporada (1947–2026
  embutidos no script); SCORING é o líder de pontos por temporada.
- Jogadores do dataset que não existem na base e jogaram em 2024+ são
  inseridos com `pais='USA'` e `bio_checado=0` — revisar a nacionalidade em
  games/admin/dadosjogadores.php.
