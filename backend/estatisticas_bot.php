<?php
/**
 * As estatísticas da liga, para o bot do WhatsApp.
 *
 * ATENÇÃO — DÍVIDA CONHECIDA: estas consultas são CÓPIA das que estão dentro
 * de estatisticas.php (as linhas de origem estão anotadas em cada uma). Foi
 * escolha consciente do Marcos em 18/08/2026: ver funcionando antes de pagar a
 * refatoração. O certo é as duas pontas lerem daqui — enquanto não lerem, toda
 * mudança de regra tem que ser feita NOS DOIS lugares, senão o bot e a página
 * passam a discordar sem ninguém notar.
 *
 * Diferença de propósito em relação à página: aqui é UMA liga por resposta —
 * a do grupo, e ELITE quando o grupo não tem liga. A página mostra as quatro.
 */

/** As temporadas da sprint em andamento, igual à página. */
function ebTemporadasDaSprint(): string
{
    return "(SELECT id FROM seasons WHERE sprint_id IN (SELECT id FROM sprints WHERE status = 'active'))";
}

/**
 * O catálogo: comando => como calcular e como escrever.
 *
 *   titulo     cabeçalho da resposta
 *   sub        a linha em itálico que explica o que o número é
 *   alto/baixo rótulos dos dois lados; baixo = null quando "menos" não faz
 *              sentido (títulos, sweeps — zero é o normal, não é notícia)
 *   ordem      'desc' = maior é melhor; 'asc' = menor é melhor (idade, seed)
 *   par        true quando a linha é uma dupla de times
 *   sql        consulta com :liga, devolvendo nome e valor
 *   calc       alternativa ao sql, quando a conta não cabe numa consulta
 */
function ebCatalogo(): array
{
    $T = ebTemporadasDaSprint();

    return [
        // ── Elenco ───────────────────────────────────────────────────
        // estatisticas.php: $youngMap
        'elencojovem' => [
            'titulo' => 'Elenco Mais Jovem', 'sub' => 'idade média do elenco',
            'alto' => '🌱 Mais jovens', 'baixo' => null, 'ordem' => 'asc',
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, ROUND(AVG(p.age),1) AS valor
                      FROM teams t LEFT JOIN players p ON p.team_id=t.id AND p.age > 0
                      WHERE t.league = :liga
                      GROUP BY t.id, t.city, t.name HAVING valor IS NOT NULL",
        ],
        // $oldMap — a página inverte o de cima; aqui é a mesma conta ao contrário.
        'elencovelho' => [
            'titulo' => 'Elenco Mais Experiente', 'sub' => 'idade média do elenco',
            'alto' => '🧓 Mais experientes', 'baixo' => null, 'ordem' => 'desc',
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, ROUND(AVG(p.age),1) AS valor
                      FROM teams t LEFT JOIN players p ON p.team_id=t.id AND p.age > 0
                      WHERE t.league = :liga
                      GROUP BY t.id, t.city, t.name HAVING valor IS NOT NULL",
        ],
        // $faMap
        'freeagency' => [
            'titulo' => 'Free Agency', 'sub' => 'contratações fechadas na FA',
            'alto' => '🖊️ Mais contratações', 'baixo' => '📦 Menos contratações', 'ordem' => 'desc',
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, COUNT(far.id) AS valor
                      FROM teams t
                      LEFT JOIN fa_requests far ON far.winner_team_id = t.id AND far.status = 'assigned'
                           AND far.season_id IN {$T}
                      WHERE t.league = :liga
                      GROUP BY t.id, t.city, t.name",
        ],
        // $top5PicksMap
        'top5' => [
            'titulo' => 'Escolhas no Top 5', 'sub' => 'jogadores escolhidos nas 5 primeiras do draft',
            'alto' => '⭐ Mais escolhas', 'baixo' => null, 'ordem' => 'desc',
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, COUNT(*) AS valor
                      FROM draft_order do_
                      JOIN draft_sessions ds ON ds.id = do_.draft_session_id
                      JOIN teams t ON t.id = do_.team_id
                      WHERE do_.pick_position <= 5 AND do_.round = 1
                        AND do_.picked_player_id IS NOT NULL
                        AND ds.league = :liga AND ds.season_id IN {$T}
                      GROUP BY t.id, t.city, t.name",
        ],

        // ── Playoff ──────────────────────────────────────────────────
        // $playoffMap — playoff = 3 pontos ou mais na temporada.
        'playoffs' => [
            'titulo' => 'Aparições no Playoff', 'sub' => 'temporadas em que chegou ao playoff',
            'alto' => '🎯 Mais playoffs', 'baixo' => '📉 Menos playoffs', 'ordem' => 'desc',
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, COUNT(DISTINCT tsp.season_id) AS valor
                      FROM teams t
                      LEFT JOIN team_season_points tsp ON tsp.team_id=t.id AND tsp.points>=3
                           AND tsp.league COLLATE utf8mb4_unicode_ci = t.league COLLATE utf8mb4_unicode_ci
                           AND tsp.season_id IN {$T}
                      WHERE t.league = :liga
                      GROUP BY t.id, t.city, t.name",
        ],
        'sequencia' => [
            'titulo' => 'Maior Sequência de Playoffs', 'sub' => 'temporadas seguidas classificado',
            'alto' => '🔥 Maior sequência', 'baixo' => null, 'ordem' => 'desc',
            'calc' => 'ebSequencias', 'calc_arg' => 'streak',
        ],
        'jejum' => [
            'titulo' => 'Maior Jejum de Playoffs', 'sub' => 'temporadas seguidas fora do playoff',
            'alto' => '😴 Maior jejum', 'baixo' => null, 'ordem' => 'desc',
            'calc' => 'ebSequencias', 'calc_arg' => 'jejum',
        ],
        // Eterno vice: perdeu final e nunca ganhou nenhuma.
        'vice' => [
            'titulo' => 'Eterno Vice', 'sub' => 'vice-campeonatos sem nenhum título',
            'alto' => '🥈 Mais vices sem taça', 'baixo' => null, 'ordem' => 'desc',
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, SUM(pb.status='runner_up') AS valor
                      FROM playoff_brackets pb
                      JOIN seasons s ON s.id = pb.season_id
                      JOIN teams t ON t.id = pb.team_id
                      WHERE s.league = :liga AND pb.season_id IN {$T}
                      GROUP BY t.id, t.city, t.name
                      HAVING valor > 0 AND SUM(pb.status='champion') = 0",
        ],
        // As três de série dependem de playoff_series.jogos. Sem série lançada
        // com o adversário elas vêm vazias — e a resposta diz isso.
        '4a0' => [
            'titulo' => 'Sweeps Aplicados (4-0)', 'sub' => 'séries vencidas sem perder um jogo',
            'alto' => '🧹 Mais sweeps', 'baixo' => null, 'ordem' => 'desc',
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, COUNT(*) AS valor
                      FROM playoff_series ps JOIN teams t ON t.id = ps.winner_team_id
                      WHERE ps.jogos = 4 AND ps.league = :liga AND ps.season_id IN {$T}
                      GROUP BY t.id, t.city, t.name",
        ],
        '0a4' => [
            'titulo' => 'Sweeps Sofridos (0-4)', 'sub' => 'séries perdidas sem vencer um jogo',
            'alto' => '🧹 Mais sweeps sofridos', 'baixo' => null, 'ordem' => 'desc',
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, COUNT(*) AS valor
                      FROM playoff_series ps
                      JOIN teams t ON t.id = IF(ps.winner_team_id = ps.team_a_id, ps.team_b_id, ps.team_a_id)
                      WHERE ps.jogos = 4 AND ps.league = :liga AND ps.season_id IN {$T}
                      GROUP BY t.id, t.city, t.name",
        ],
        'jogo7' => [
            'titulo' => 'Guerreiros do Jogo 7', 'sub' => 'séries decididas no jogo 7 — vale pros dois lados',
            'alto' => '🎬 Mais jogos 7', 'baixo' => null, 'ordem' => 'desc',
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, COUNT(*) AS valor
                      FROM playoff_series ps JOIN teams t ON t.id IN (ps.team_a_id, ps.team_b_id)
                      WHERE ps.jogos = 7 AND ps.league = :liga AND ps.season_id IN {$T}
                      GROUP BY t.id, t.city, t.name",
        ],

        // ── Confrontos (dupla de times) ───────────────────────────────
        'rivalidades' => [
            'titulo' => 'Maiores Rivalidades', 'sub' => 'duplas que mais se enfrentaram no playoff',
            'alto' => '⚔️ Mais confrontos', 'baixo' => null, 'ordem' => 'desc', 'par' => true,
            'sql' => "SELECT CONCAT(a.name,' × ',b.name) AS nome, COUNT(*) AS valor
                      FROM playoff_matches pm
                      JOIN seasons s ON s.id = pm.season_id
                      JOIN teams a ON a.id = LEAST(pm.team1_id, pm.team2_id)
                      JOIN teams b ON b.id = GREATEST(pm.team1_id, pm.team2_id)
                      WHERE pm.team1_id > 0 AND pm.team2_id > 0
                        AND s.league = :liga AND pm.season_id IN {$T}
                      GROUP BY a.id, b.id, a.name, b.name HAVING valor >= 2",
        ],
        'dominio' => [
            'titulo' => 'Domínio Total', 'sub' => 'duplas em que um time venceu TODOS os confrontos',
            'alto' => '💀 Freguesia', 'baixo' => null, 'ordem' => 'desc', 'par' => true,
            'calc' => 'ebDominio',
        ],
        // $pairsMap
        'duplas' => [
            'titulo' => 'Duplas que Mais Trocaram', 'sub' => 'trades aceitas entre os dois times',
            'alto' => '🔄 Maiores parceiros', 'baixo' => null, 'ordem' => 'desc', 'par' => true,
            'sql' => "SELECT CONCAT(a.name,' × ',b.name) AS nome, COUNT(*) AS valor
                      FROM trades tr
                      JOIN teams a ON a.id = LEAST(tr.from_team_id, tr.to_team_id)
                      JOIN teams b ON b.id = GREATEST(tr.from_team_id, tr.to_team_id)
                      WHERE tr.status = 'accepted' AND tr.from_team_id <> tr.to_team_id
                        AND a.league = :liga
                      GROUP BY a.id, b.id, a.name, b.name",
        ],
        // $direcionalMap — aqui a ordem importa, então NÃO normaliza a dupla.
        'unidirecionais' => [
            'titulo' => 'Trades Unidirecionais', 'sub' => 'quem mandou mais trades pra um mesmo time',
            'alto' => '📤 Mais unidirecionais', 'baixo' => null, 'ordem' => 'desc', 'par' => true,
            'sql' => "SELECT CONCAT(a.name,' → ',b.name) AS nome, COUNT(*) AS valor
                      FROM trades tr
                      JOIN teams a ON a.id = tr.from_team_id
                      JOIN teams b ON b.id = tr.to_team_id
                      WHERE tr.status = 'accepted' AND a.league = :liga
                      GROUP BY a.id, b.id, a.name, b.name",
        ],

        // ── Trades ───────────────────────────────────────────────────
        // As três abaixo somam `trades` (dois times) com `multi_trades` (três
        // ou mais). Trade de N vias mora noutra tabela, e as contas só olhavam
        // a primeira — um time cujas trocas foram todas multi aparecia com
        // ZERO, e quem tinha as duas coisas aparecia com metade.
        'parceiros' => [
            'titulo' => 'Diversidade de Parceiros', 'sub' => 'franquias diferentes com quem já trocou',
            'alto' => '🌐 Mais parceiros', 'baixo' => '🏝️ Menos interativos', 'ordem' => 'desc',
            // Cada troca vira duas arestas (ida e volta) pra o time aparecer
            // dos dois lados sem precisar de IF no meio da contagem.
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, COUNT(DISTINCT e.parceiro) AS valor
                      FROM teams t
                      LEFT JOIN (
                          SELECT tr.from_team_id AS eu, tr.to_team_id AS parceiro
                            FROM trades tr WHERE tr.status='accepted'
                          UNION ALL
                          SELECT tr.to_team_id, tr.from_team_id
                            FROM trades tr WHERE tr.status='accepted'
                          UNION ALL
                          SELECT mi.from_team_id, mi.to_team_id
                            FROM multi_trade_items mi
                            JOIN multi_trades mt ON mt.id = mi.trade_id
                           WHERE mt.status='accepted'
                          UNION ALL
                          SELECT mi.to_team_id, mi.from_team_id
                            FROM multi_trade_items mi
                            JOIN multi_trades mt ON mt.id = mi.trade_id
                           WHERE mt.status='accepted'
                      ) e ON e.eu = t.id AND e.parceiro <> t.id
                      WHERE t.league = :liga
                      GROUP BY t.id, t.city, t.name",
        ],
        'tradesenviadas' => [
            'titulo' => 'Trades Enviadas', 'sub' => 'propostas que o time mandou',
            'alto' => '📤 Mais enviadas', 'baixo' => '🤐 Menos enviadas', 'ordem' => 'desc',
            // Na multi quem propõe é o created_by_team_id — os outros
            // participantes entraram na proposta de alguém, não fizeram uma.
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome,
                             (SELECT COUNT(*) FROM trades tr WHERE tr.from_team_id = t.id)
                           + (SELECT COUNT(*) FROM multi_trades mt WHERE mt.created_by_team_id = t.id) AS valor
                      FROM teams t
                      WHERE t.league = :liga",
        ],
        'tradesaceitas' => [
            'titulo' => 'Trades Aceitas', 'sub' => 'trades concluídas com o time envolvido',
            'alto' => '🤝 Mais aceitas', 'baixo' => '🧊 Menos aceitas', 'ordem' => 'desc',
            // Conta pra TODO time envolvido, quem propôs e quem topou: os dois
            // aceitaram a mesma troca. Na multi, vale pros N participantes.
            // O DISTINCT no mt.id é o que evita contar a mesma multi uma vez
            // por item — uma troca de cinco jogadores viraria cinco trades.
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome,
                             (SELECT COUNT(*) FROM trades tr
                               WHERE tr.status='accepted'
                                 AND (tr.from_team_id = t.id OR tr.to_team_id = t.id))
                           + (SELECT COUNT(DISTINCT mt.id)
                                FROM multi_trades mt
                                JOIN multi_trade_items mi ON mi.trade_id = mt.id
                               WHERE mt.status='accepted'
                                 AND (mi.from_team_id = t.id OR mi.to_team_id = t.id)) AS valor
                      FROM teams t
                      WHERE t.league = :liga",
        ],
        'tradesrecusadas' => [
            'titulo' => 'Trades Recusadas', 'sub' => 'propostas rejeitadas com o time envolvido',
            'alto' => '❌ Mais recusadas', 'baixo' => '✅ Menos recusadas', 'ordem' => 'desc',
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, COUNT(tr.id) AS valor
                      FROM teams t
                      LEFT JOIN trades tr ON (tr.from_team_id=t.id OR tr.to_team_id=t.id) AND tr.status='rejected'
                      WHERE t.league = :liga
                      GROUP BY t.id, t.city, t.name",
        ],
    ];
}

/**
 * Sequência e jejum de playoff, numa passada só.
 *
 * Não cabe em SQL porque "temporadas seguidas" depende da ORDEM: um time que
 * foi ao playoff nas temporadas 1, 2 e 5 tem sequência 2, não 3.
 * Cópia de estatisticas.php ($streakMap/$jejumMap).
 */
function ebSequencias(PDO $pdo, string $liga, string $qual): array
{
    $T = ebTemporadasDaSprint();
    $st = $pdo->prepare("
        SELECT tsp.team_id, CONCAT(t.city,' ',t.name) AS nome, s.season_number, tsp.points
        FROM team_season_points tsp
        JOIN teams t ON t.id = tsp.team_id
        JOIN seasons s ON s.id = tsp.season_id
        WHERE tsp.league = :liga AND tsp.season_id IN {$T}
        ORDER BY tsp.team_id, s.season_number ASC");
    $st->execute([':liga' => $liga]);

    $porTime = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $porTime[(int)$r['team_id']]['nome'] = $r['nome'];
        $porTime[(int)$r['team_id']]['pts'][(int)$r['season_number']] = (int)$r['points'];
    }

    $out = [];
    foreach ($porTime as $d) {
        $pts = $d['pts']; ksort($pts);
        $max = 0; $atual = 0;
        foreach ($pts as $p) {
            // 3 pontos ou mais na temporada = chegou ao playoff.
            $conta = $qual === 'streak' ? ($p >= 3) : ($p < 3);
            if ($conta) { $atual++; $max = max($max, $atual); } else $atual = 0;
        }
        if ($max > 0) $out[] = ['nome' => $d['nome'], 'valor' => $max];
    }
    return $out;
}

/**
 * Domínio total: duplas em que um lado venceu TODOS os confrontos.
 *
 * Saldo positivo com uma derrota no meio não é domínio, é vantagem — por isso
 * o par só entra quando o outro lado tem zero. Cópia da lógica que hoje está
 * em estatisticas.php ($dominioMap).
 */
function ebDominio(PDO $pdo, string $liga): array
{
    $T = ebTemporadasDaSprint();
    $st = $pdo->prepare("
        SELECT pm.team1_id, pm.team2_id, pm.winner_id, t1.name AS n1, t2.name AS n2
        FROM playoff_matches pm
        JOIN seasons s ON s.id = pm.season_id
        JOIN teams t1 ON t1.id = pm.team1_id
        JOIN teams t2 ON t2.id = pm.team2_id
        WHERE pm.winner_id > 0 AND pm.team1_id > 0 AND pm.team2_id > 0
          AND s.league = :liga AND pm.season_id IN {$T}");
    $st->execute([':liga' => $liga]);

    $pares = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $a = min((int)$d['team1_id'], (int)$d['team2_id']);
        $b = max((int)$d['team1_id'], (int)$d['team2_id']);
        $k = $a . '|' . $b;
        if (!isset($pares[$k])) $pares[$k] = ['vit' => [$a => 0, $b => 0], 'nomes' => []];
        $pares[$k]['nomes'][(int)$d['team1_id']] = $d['n1'];
        $pares[$k]['nomes'][(int)$d['team2_id']] = $d['n2'];
        $w = (int)$d['winner_id'];
        if (isset($pares[$k]['vit'][$w])) $pares[$k]['vit'][$w]++;
    }

    $out = [];
    foreach ($pares as $p) {
        $ids = array_keys($p['vit']);
        [$x, $y] = [$p['vit'][$ids[0]], $p['vit'][$ids[1]]];
        if ($x + $y < 2) continue;        // um duelo só não é domínio
        if ($x > 0 && $y > 0) continue;   // levou uma: não é domínio
        $dono  = $x > $y ? $ids[0] : $ids[1];
        $outro = $dono === $ids[0] ? $ids[1] : $ids[0];
        $out[] = [
            'nome'  => ($p['nomes'][$dono] ?? '?') . ' sobre ' . ($p['nomes'][$outro] ?? '?'),
            'valor' => $x + $y,
        ];
    }
    return $out;
}

/** As linhas de uma estatística, já ordenadas. */
function ebLinhas(PDO $pdo, array $def, string $liga): array
{
    try {
        if (!empty($def['calc'])) {
            $linhas = $def['calc'] === 'ebSequencias'
                ? ebSequencias($pdo, $liga, $def['calc_arg'])
                : ebDominio($pdo, $liga);
        } else {
            $st = $pdo->prepare($def['sql']);
            $st->execute([':liga' => $liga]);
            $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('[estatisticas bot] ' . $e->getMessage());
        return [];
    }

    $asc = ($def['ordem'] ?? 'desc') === 'asc';
    usort($linhas, function ($a, $b) use ($asc) {
        $x = (float)$a['valor']; $y = (float)$b['valor'];
        return ($asc ? $x <=> $y : $y <=> $x) ?: strcasecmp($a['nome'], $b['nome']);
    });
    return $linhas;
}

/** Um número inteiro sai sem casa decimal; 24.0 vira 24. */
function ebNum($v): string
{
    $f = (float)$v;
    return $f == (int)$f ? (string)(int)$f : number_format($f, 1, ',', '');
}

function ebBloco(string $rotulo, array $linhas): string
{
    $txt = "*{$rotulo}*\n";
    foreach ($linhas as $i => $l) {
        $txt .= ($i + 1) . '. ' . $l['nome'] . ' — ' . ebNum($l['valor']) . "\n";
    }
    return $txt;
}

/**
 * A resposta de um comando de estatística.
 *
 * Sempre UMA liga: a do grupo, ELITE quando o grupo não tem. Não existe versão
 * "todas as ligas" aqui de propósito — no grupo da NEXT ninguém perguntou pela
 * ELITE, e quatro tabelas numa mensagem é parede de texto.
 */
/**
 * Outros nomes que levam ao mesmo lugar.
 *
 * "troca" é como metade da liga fala, e o comando que existe é "trade" —
 * quem digita /trocasaceitas não recebe nada e conclui que o bot não tem.
 * Aqui os dois funcionam.
 *
 * O 'ofertasenviadas' fica de apelido porque ERA o nome oficial: quem
 * aprendeu ele continua sendo atendido, em vez de descobrir sozinho que o
 * comando mudou.
 *
 * @return array<string,string> apelido => chave do catálogo
 */
function ebApelidos(): array
{
    return [
        // /trades e /trocas são o atalho: quem digita o nome curto quer saber
        // quem trocou, e trocar é a que foi aceita. As outras três precisam
        // do sufixo justamente porque são o recorte, não o assunto.
        'trades'           => 'tradesaceitas',
        'trocas'           => 'tradesaceitas',
        'trocasenviadas'   => 'tradesenviadas',
        'trocasaceitas'    => 'tradesaceitas',
        'trocasrecusadas'  => 'tradesrecusadas',
        'ofertasenviadas'  => 'tradesenviadas',
        'ofertas'          => 'tradesenviadas',
    ];
}

function ebResponder(PDO $pdo, string $comando, ?string $ligaDoGrupo): ?string
{
    $cat = ebCatalogo();
    $comando = ebApelidos()[$comando] ?? $comando;
    if (!isset($cat[$comando])) return null;
    $def = $cat[$comando];

    require_once __DIR__ . '/../api/whatsapp-comandos.php';
    $liga = wcLigaPreferida($ligaDoGrupo);

    $linhas = ebLinhas($pdo, $def, $liga);
    if (!$linhas) {
        return "*{$def['titulo']}* — {$liga}\n\nSem dados ainda.";
    }

    $txt = "*{$def['titulo']}* — {$liga}\n_{$def['sub']}_\n\n";
    $txt .= ebBloco($def['alto'], array_slice($linhas, 0, 5));

    // O "menos" só entra quando faz sentido E quando há gente suficiente pra
    // os dois lados não mostrarem os mesmos times.
    if (!empty($def['baixo']) && count($linhas) >= 10) {
        $txt .= "\n" . ebBloco($def['baixo'], array_reverse(array_slice($linhas, -5)));
    }
    return rtrim($txt);
}

/** O /estatisticas: a lista do que existe, agrupada como a pessoa pensa. */
function ebListar(?string $ligaDoGrupo): string
{
    require_once __DIR__ . '/../api/whatsapp-comandos.php';
    $liga = wcLigaPreferida($ligaDoGrupo);

    $grupos = [
        'Elenco e draft' => ['elencojovem', 'elencovelho', 'freeagency', 'top5'],
        'Playoff'        => ['playoffs', 'sequencia', 'jejum', 'vice', '4a0', '0a4', 'jogo7'],
        'Confrontos'     => ['rivalidades', 'dominio', 'duplas', 'unidirecionais'],
        // O /trades abre por ser o atalho, e vem escrito que é atalho: sem
        // isso a lista mostraria "Trades Aceitas" duas vezes e pareceria
        // defeito. O /parceiros fecha porque responde outra pergunta — com
        // QUANTOS, e não quantas.
        'Trades'         => ['trades', 'tradesaceitas', 'tradesenviadas', 'tradesrecusadas', 'parceiros'],
    ];

    $cat = ebCatalogo();
    $txt = "*Estatísticas da {$liga}*\n_top 5 de cada uma, na liga deste grupo_\n";
    foreach ($grupos as $titulo => $cmds) {
        $txt .= "\n*{$titulo}*\n";
        foreach ($cmds as $c) {
            // Apelido também aparece na lista, com o rótulo do oficial mais a
            // marca de atalho — senão /trades existiria e ninguém saberia.
            $chave = ebApelidos()[$c] ?? $c;
            if (!isset($cat[$chave])) continue;
            $marca = $chave === $c ? '' : ' _(atalho)_';
            $txt .= "/{$c} — " . $cat[$chave]['titulo'] . $marca . "\n";
        }
    }
    return rtrim($txt);
}
