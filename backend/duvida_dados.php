<?php
/**
 * O /duvida CONSULTANDO O BANCO.
 *
 * Até aqui o bot só tinha texto no contexto: regras, guia, configuração da
 * liga. Isso responde "como funciona", mas não "quem foi campeão da T1" nem
 * "qual lenda mais evoluiu" — e essas são metade das perguntas do grupo.
 *
 * Antecipar cada pergunta numa função pronta não escala: seriam dezenas, e a
 * pergunta seguinte seria sempre a que ficou de fora. Então o modelo escreve a
 * consulta e este arquivo decide se ela pode rodar.
 *
 * ── POR QUE ISSO NÃO É PERIGOSO AQUI ────────────────────────────────────
 *
 * Não é "dar o banco pro modelo". São quatro travas, e todas precisam passar:
 *
 *   1. SÓ LEITURA. A consulta tem que começar em SELECT ou WITH, e qualquer
 *      palavra que escreva (INSERT, UPDATE, DROP, GRANT...) reprova o texto
 *      inteiro. Uma instrução por vez — `;` no meio é recusado.
 *   2. TABELAS NA LISTA. Só as que interessam pra liga. `users` fica de fora
 *      por inteiro, e com ela e-mail, telefone e hash de senha.
 *   3. COLUNAS PROIBIDAS. Cinto e suspensório: mesmo numa tabela liberada,
 *      citar coluna de dado pessoal reprova.
 *   4. TETO DE LINHAS. LIMIT é imposto, não pedido — o banco é pequeno e
 *      nenhuma resposta de WhatsApp precisa de mais que algumas dezenas.
 *
 * O modelo também não vê o resultado cru virar resposta: ele recebe as linhas
 * e escreve o texto, e é aí que a última instrução vale — não inventar o que a
 * consulta não trouxe.
 */

/**
 * As tabelas que o bot pode ler.
 *
 * Critério: dado de liga, que qualquer GM vê no site de qualquer jeito. Fora
 * ficam as de gente (users, sessões, push), as de dinheiro do games e as de
 * operação do bot — nada disso responde pergunta de basquete.
 */
const DUVIDA_TABELAS = [
    'teams', 'players', 'seasons', 'sprints', 'leagues',
    'season_standings', 'playoff_results', 'season_awards', 'team_ranking_points',
    'picks', 'trades', 'trade_items',
    'player_season_stats', 'player_season_log',
    'draft_pool', 'draft_order', 'draft_sessions',
    'free_agents', 'waiver_retention', 'leilao_jogadores',
    'divisions', 'league_settings', 'league_sprint_config',
];

/** Colunas que não saem daqui nem por acidente. */
const DUVIDA_COLUNAS_PROIBIDAS = [
    'password', 'password_hash', 'email', 'phone', 'whatsapp', 'token',
    'reset_token', 'verification_token', 'birth_date', 'api_key', 'secret',
];

/** Quanto o modelo pode trazer por consulta. */
const DUVIDA_LIMITE_LINHAS = 60;

/**
 * O esquema, escrito a partir do banco de verdade.
 *
 * Gerado e não transcrito: uma coluna nova no app apareceria aqui sozinha, e
 * uma lista escrita à mão viraria mentira no primeiro ALTER TABLE — o modelo
 * escreveria consulta com coluna que não existe e levaria erro.
 */
function duvidaEsquemaParaIA(PDO $pdo): string
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $l = ['TABELAS QUE VOCÊ PODE CONSULTAR (MySQL)', ''];
    foreach (DUVIDA_TABELAS as $t) {
        try {
            $cols = [];
            foreach ($pdo->query("SHOW COLUMNS FROM `{$t}`") as $c) {
                $nome = (string)$c['Field'];
                if (duvidaColunaProibida($nome)) continue;
                // O tipo entra curto: "int", "varchar", "decimal" bastam pro
                // modelo saber se compara com número ou com texto.
                $tipo = preg_replace('/\(.*$/', '', (string)$c['Type']);
                $cols[] = $nome . ':' . $tipo;
            }
            if ($cols) $l[] = "- {$t}(" . implode(', ', $cols) . ')';
        } catch (Throwable $e) { /* tabela ausente nesta instalação */ }
    }

    $l[] = '';
    $l[] = 'COMO OS DADOS SE LIGAM (o que não dá pra adivinhar pelo nome):';
    $l[] = '- Time: teams(id, city, name, league, conference). O nome completo é city + name.';
    $l[] = '- Temporada: seasons(id, league, season_number, year). season_number é a T1, T2… da liga;';
    $l[] = '  year é o ano fictício. Uma pergunta sobre "temporada 1" é season_number = 1.';
    $l[] = '- Campeão e colocação de playoff: playoff_results(season_id, team_id, position).';
    $l[] = '  position = 1 é o CAMPEÃO, 2 o vice. Quem não foi aos playoffs não tem linha aqui.';
    $l[] = '- Classificação da fase regular: season_standings(season_id, team_id, wins, losses, position).';
    $l[] = '- Prêmios: season_awards(season_id, team_id, award_type, player_name).';
    $l[] = '- Pontuação do ranking: team_ranking_points(team_id, season_id, league, total_points e as parciais).';
    $l[] = '- Elenco de hoje: players(team_id, name, age, ovr, position, is_lenda, seasons_in_league).';
    $l[] = '  is_lenda = 1 marca as LENDAS. players só tem quem está em algum time agora.';
    $l[] = '- Histórico de cada jogador: player_season_log(player_name, season_number, year, team_name, ovr, age).';
    $l[] = '  É AQUI que se vê evolução de OVR ao longo das temporadas — players só tem o valor atual.';
    $l[] = '- Estatística por temporada: player_season_stats(player_id, season_id, season_number, games,';
    $l[] = '  min_pg, pts_pg, reb_pg, ast_pg, stl_pg, blk_pg). stl_pg é ROUBO e blk_pg é TOCO.';
    $l[] = '- Trocas: trades(from_team_id, to_team_id, status, season_year) e trade_items(trade_id,';
    $l[] = '  player_id, pick_id, from_team). status "accepted" é troca que aconteceu.';
    $l[] = '- Picks: picks(team_id = dono hoje, original_team_id = de quem era, season_year, round).';

    return $cache = implode("\n", $l);
}

/** A coluna carrega dado pessoal? */
function duvidaColunaProibida(string $nome): bool
{
    $n = mb_strtolower($nome);
    foreach (DUVIDA_COLUNAS_PROIBIDAS as $p) {
        if (str_contains($n, $p)) return true;
    }
    return false;
}

/**
 * Roda a consulta do modelo, se ela passar pelas travas.
 *
 * @return array{ok:bool, linhas:?array, erro:?string, sql:string}
 */
function duvidaConsultar(PDO $pdo, string $sql): array
{
    $sql = trim($sql);
    $falha = fn(string $m) => ['ok' => false, 'linhas' => null, 'erro' => $m, 'sql' => $sql];

    if ($sql === '') return $falha('Consulta vazia.');
    if (mb_strlen($sql) > 4000) return $falha('Consulta longa demais.');

    // Uma instrução por vez. O `;` do fim é aceito porque é hábito de quem
    // escreve SQL; no meio, é tentativa de emendar uma segunda.
    $sql = rtrim($sql, "; \t\n\r");
    if (str_contains($sql, ';')) return $falha('Mande uma consulta só, sem ";" no meio.');

    // Comentário fora: é por dentro dele que se esconde palavra proibida.
    $limpo = preg_replace('/--[^\n]*|#[^\n]*|\/\*.*?\*\//s', ' ', $sql);
    $baixo = mb_strtolower($limpo);

    if (!preg_match('/^\s*(select|with)\b/', $baixo)) {
        return $falha('Só consulta de leitura: comece com SELECT.');
    }

    $proibidas = ['insert', 'update', 'delete', 'drop', 'truncate', 'alter', 'create',
                  'replace', 'grant', 'revoke', 'rename', 'load_file', 'outfile',
                  'dumpfile', 'into ', 'information_schema', 'mysql.', 'sleep(',
                  'benchmark(', 'set ', 'call ', 'handler ', 'lock ', 'union all select 1'];
    foreach ($proibidas as $p) {
        if (str_contains($baixo, $p)) return $falha('A consulta usa algo que não é permitido aqui.');
    }

    foreach (DUVIDA_COLUNAS_PROIBIDAS as $c) {
        if (str_contains($baixo, $c)) return $falha('Essa consulta pede dado pessoal, que não sai daqui.');
    }

    /* As tabelas citadas têm que estar na lista. Pego o que vem depois de FROM
       e de JOIN — é onde tabela aparece — e confiro uma a uma. */
    if (preg_match_all('/\b(?:from|join)\s+`?([a-z_][a-z0-9_]*)`?/i', $baixo, $m)) {
        foreach ($m[1] as $tab) {
            if (!in_array($tab, DUVIDA_TABELAS, true)) {
                return $falha("A tabela `{$tab}` não pode ser consultada por aqui.");
            }
        }
    }

    // LIMIT é imposto: sem ele, um SELECT sem WHERE traria vinte mil linhas
    // do player_season_log pra dentro do contexto do modelo.
    if (!preg_match('/\blimit\s+\d+/i', $baixo)) {
        $sql .= ' LIMIT ' . DUVIDA_LIMITE_LINHAS;
    }

    try {
        $st = $pdo->query($sql);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($linhas) > DUVIDA_LIMITE_LINHAS) {
            $linhas = array_slice($linhas, 0, DUVIDA_LIMITE_LINHAS);
        }
        return ['ok' => true, 'linhas' => $linhas, 'erro' => null, 'sql' => $sql];
    } catch (Throwable $e) {
        // O erro do banco volta pro modelo de propósito: com "Unknown column
        // x" ele conserta a consulta sozinho na tentativa seguinte.
        return $falha('Erro no SQL: ' . $e->getMessage());
    }
}

/**
 * O EDITAL VIROU FERRAMENTA, e deixou de morar no prompt.
 *
 * Ele ia inteiro no contexto de toda pergunta — 17,5 mil tokens — e, depois
 * que a conversa virou multi-turno, isso passou a ser pago em CADA rodada. O
 * efeito foi medido: com o edital dentro, o modelo estourava 15s sem começar a
 * responder, o pedido caía de modelo em modelo e a pergunta levava 100s pra
 * terminar em "não consegui".
 *
 * Fora do prompt, ele continua ao alcance: o modelo pede quando precisa, e só
 * pra pergunta de regra — que é o que a organização queria (o edital entra pro
 * que o app não tem).
 *
 * Busca por palavra, e não semântica: os artigos são curtos e o vocabulário da
 * liga é pequeno. "Cap", "leilão", "punição" acham o que precisam.
 */
function duvidaBuscarNoEdital(PDO $pdo, string $league, string $termo): string
{
    require_once __DIR__ . '/edital_texto.php';

    $termo = trim($termo);
    if ($termo === '') return 'Diga o que procurar no edital.';

    $texto = editalTexto($pdo, $league);
    if ($texto === null) return "A {$league} não tem edital cadastrado.";

    // Cada palavra com 3+ letras conta; artigo que casa com mais vem antes.
    $palavras = array_values(array_filter(
        preg_split('/\s+/u', mb_strtolower($termo)),
        fn($p) => mb_strlen($p) >= 3
    ));
    if (!$palavras) $palavras = [mb_strtolower($termo)];

    $achados = [];
    foreach (editalArtigos($texto) as $a) {
        $corpo = mb_strtolower($a['texto']);
        $pontos = 0;
        foreach ($palavras as $p) if (str_contains($corpo, $p)) $pontos++;
        if ($pontos > 0) $achados[] = ['pontos' => $pontos, 'art' => $a];
    }
    if (!$achados) return "Não achei nada sobre \"{$termo}\" no edital da {$league}.";

    usort($achados, fn($x, $y) => $y['pontos'] <=> $x['pontos']);
    $achados = array_slice($achados, 0, 4);

    $out = [];
    foreach ($achados as $x) {
        $a = $x['art'];
        // 700 por artigo: quatro artigos cabem sem virar outro edital dentro
        // do contexto, que é o que esta função existe pra evitar.
        $out[] = 'Art. ' . $a['num'] . ' — ' . editalTituloLegivel($a['capitulo']) . "\n"
               . mb_substr($a['texto'], 0, 700);
    }
    return implode("\n\n———\n\n", $out);
}

/** O resultado em texto, do jeito que o modelo lê melhor. */
function duvidaResultadoParaIA(array $r): string
{
    if (!$r['ok']) return 'ERRO: ' . $r['erro'];
    $linhas = $r['linhas'] ?? [];
    if (!$linhas) return 'A consulta rodou e não voltou nenhuma linha.';

    $out = [count($linhas) . ' linha(s):'];
    foreach ($linhas as $l) {
        $campos = [];
        foreach ($l as $k => $v) $campos[] = $k . '=' . ($v === null ? 'NULL' : $v);
        $out[] = '- ' . implode(' | ', $campos);
    }
    return implode("\n", $out);
}
