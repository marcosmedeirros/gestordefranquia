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
        // Aparições: foi ao playoff = resultado de playoff registrado na
        // temporada. O /playoffs é o chaveamento (whatsapp-comandos.php); a
        // estatística é o /idasplayoffs.
        'idasplayoffs' => [
            'titulo' => 'Aparições no Playoff', 'sub' => 'temporadas da sprint em que foi ao playoff',
            'alto' => '🎯 Mais playoffs', 'baixo' => '📉 Menos playoffs', 'ordem' => 'desc',
            'calc' => 'epAparicoes',
        ],
        'sequencia' => [
            'titulo' => 'Maior Sequência de Playoffs', 'sub' => 'temporadas seguidas classificado',
            'alto' => '🔥 Maior sequência', 'baixo' => null, 'ordem' => 'desc',
            'calc' => 'epSequenciaPlayoff', 'sem_zero' => true,
        ],
        'jejum' => [
            'titulo' => 'Maior Jejum de Playoffs', 'sub' => 'temporadas seguidas fora do playoff',
            'alto' => '😴 Maior jejum', 'baixo' => null, 'ordem' => 'desc',
            'calc' => 'epJejumPlayoff', 'sem_zero' => true,
        ],
        // Títulos, dinastia, eterno vice e seed médio: a conta é a da página,
        // em backend/estatisticas_playoff.php (registro de fim de temporada).
        'titulos' => [
            'titulo' => 'Ranking de Títulos', 'sub' => 'quem mais foi campeão na sprint',
            'alto' => '🏆 Mais títulos', 'baixo' => null, 'ordem' => 'desc',
            'calc' => 'epTitulos',
        ],
        'dinastia' => [
            'titulo' => 'Maior Dinastia', 'sub' => 'títulos em temporadas seguidas',
            'alto' => '🔥 Maior sequência', 'baixo' => null, 'ordem' => 'desc',
            'calc' => 'epDinastia',
        ],
        'vice' => [
            'titulo' => 'Eterno Vice', 'sub' => 'vice-campeonatos sem nenhum título',
            'alto' => '🥈 Mais vices sem taça', 'baixo' => null, 'ordem' => 'desc',
            'calc' => 'epEternoVice',
        ],
        'seed' => [
            'titulo' => 'Seed Médio no Playoff', 'sub' => 'posição média com que entra no playoff; menor é mais favorito',
            'alto' => '🌡️ Melhor seed médio', 'baixo' => '📉 Pior seed médio', 'ordem' => 'asc',
            'calc' => 'epSeedMedio',
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
            'alto' => '⚔️ Mais confrontos', 'baixo' => null, 'ordem' => 'desc', 'par' => true, 'sep' => ' × ',
            'calc' => 'epRivalidades',
        ],
        'dominio' => [
            'titulo' => 'Domínio Total', 'sub' => 'duplas em que um time venceu TODOS os confrontos',
            'alto' => '💀 Freguesia', 'baixo' => null, 'ordem' => 'desc', 'par' => true, 'sep' => ' sobre ',
            'calc' => 'epDominio',
        ],
        // $pairsMap — só a sprint ativa, pelo created_at, igual à página. O fim
        // de sprint já apaga as trades simples; o corte fica explícito mesmo assim.
        'duplas' => [
            'titulo' => 'Duplas que Mais Trocaram', 'sub' => 'trades aceitas entre os dois times',
            'alto' => '🔄 Maiores parceiros', 'baixo' => null, 'ordem' => 'desc', 'par' => true,
            'sql' => "SELECT CONCAT(a.name,' × ',b.name) AS nome, COUNT(*) AS valor
                      FROM trades tr
                      JOIN teams a ON a.id = LEAST(tr.from_team_id, tr.to_team_id)
                      JOIN teams b ON b.id = GREATEST(tr.from_team_id, tr.to_team_id)
                      WHERE tr.status = 'accepted' AND tr.from_team_id <> tr.to_team_id
                        AND a.league = :liga
                        AND tr.created_at >= (SELECT COALESCE(MAX(s.start_date), '1900-01-01') FROM sprints s WHERE s.league = :liga AND s.status = 'active')
                      GROUP BY a.id, b.id, a.name, b.name",
        ],
        // $direcionalMap — aqui a ordem importa, então NÃO normaliza a dupla.
        // Mesmo corte das duplas: só a sprint ativa.
        'unidirecionais' => [
            'titulo' => 'Trades Unidirecionais', 'sub' => 'quem mandou mais trades pra um mesmo time',
            'alto' => '📤 Mais unidirecionais', 'baixo' => null, 'ordem' => 'desc', 'par' => true,
            'sql' => "SELECT CONCAT(a.name,' → ',b.name) AS nome, COUNT(*) AS valor
                      FROM trades tr
                      JOIN teams a ON a.id = tr.from_team_id
                      JOIN teams b ON b.id = tr.to_team_id
                      WHERE tr.status = 'accepted' AND a.league = :liga
                        AND tr.created_at >= (SELECT COALESCE(MAX(s.start_date), '1900-01-01') FROM sprints s WHERE s.league = :liga AND s.status = 'active')
                      GROUP BY a.id, b.id, a.name, b.name",
        ],

        // ── Trades ───────────────────────────────────────────────────
        // As três abaixo somam `trades` (dois times) com `multi_trades` (três
        // ou mais). Trade de N vias mora noutra tabela, e as contas só olhavam
        // a primeira — um time cujas trocas foram todas multi aparecia com
        // ZERO, e quem tinha as duas coisas aparecia com metade.
        // ── SÓ A SPRINT ATUAL ────────────────────────────────────────
        // As quatro contas abaixo recortam pela sprint aberta da liga, igual
        // às outras oito do catálogo. Elas eram as únicas somando desde o
        // começo de tudo, e por isso diziam número que ninguém reconhecia.
        //
        // O recorte é por DATA e não por season_id: trade não tem season_id,
        // tem created_at. A data de início vem de sprints.start_date da
        // sprint com status 'active' daquela liga.
        //
        // SEM recorte por ciclo (14/09/2026): é a sprint ativa INTEIRA, todos
        // os ciclos dela — pedido do Marcos. Por isso o número pode passar do
        // max_trades, que é por ciclo; não é bug, é outro período.
        'parceiros' => [
            'titulo' => 'Diversidade de Parceiros', 'sub' => 'franquias diferentes com quem trocou na sprint',
            'alto' => '🌐 Mais parceiros', 'baixo' => '🏝️ Menos interativos', 'ordem' => 'desc',
            // Cada troca vira duas arestas (ida e volta) pra o time aparecer
            // dos dois lados sem precisar de IF no meio da contagem.
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, COUNT(DISTINCT e.parceiro) AS valor
                      FROM teams t
                      LEFT JOIN (
                          SELECT tr.from_team_id AS eu, tr.to_team_id AS parceiro
                            FROM trades tr WHERE tr.status='accepted' AND tr.created_at >= (SELECT COALESCE(MAX(s.start_date), '1900-01-01') FROM sprints s WHERE s.league = :liga AND s.status = 'active')
                          UNION ALL
                          SELECT tr.to_team_id, tr.from_team_id
                            FROM trades tr WHERE tr.status='accepted' AND tr.created_at >= (SELECT COALESCE(MAX(s.start_date), '1900-01-01') FROM sprints s WHERE s.league = :liga AND s.status = 'active')
                          UNION ALL
                          SELECT mi.from_team_id, mi.to_team_id
                            FROM multi_trade_items mi
                            JOIN multi_trades mt ON mt.id = mi.trade_id
                           WHERE mt.status='accepted' AND mt.created_at >= (SELECT COALESCE(MAX(s.start_date), '1900-01-01') FROM sprints s WHERE s.league = :liga AND s.status = 'active')
                          UNION ALL
                          SELECT mi.to_team_id, mi.from_team_id
                            FROM multi_trade_items mi
                            JOIN multi_trades mt ON mt.id = mi.trade_id
                           WHERE mt.status='accepted' AND mt.created_at >= (SELECT COALESCE(MAX(s.start_date), '1900-01-01') FROM sprints s WHERE s.league = :liga AND s.status = 'active')
                      ) e ON e.eu = t.id AND e.parceiro <> t.id
                      WHERE t.league = :liga
                      GROUP BY t.id, t.city, t.name",
        ],
        'toppicks' => [
            'titulo' => 'Picks de 1ª Rodada', 'sub' => 'quantas primeiras rodadas o time tem em mãos',
            'alto' => '💎 Mais picks de 1ª', 'baixo' => '🕳️ Menos picks de 1ª', 'ordem' => 'desc',
            // SÓ a primeira rodada: é ela que muda o rumo de uma franquia, e
            // somar a segunda junto empataria todo mundo — segunda rodada
            // quase ninguém guarda nem cobra.
            //
            // O corte por ano usa a MESMA régua da página de Picks
            // (anoDeCorteDasPicks, injetada como :anopick por ebLinhas):
            // sem ele o time apareceria com pick de draft que já aconteceu, e
            // o bot diria um número que a tela ao lado desmente.
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, COUNT(p.id) AS valor
                      FROM teams t
                      LEFT JOIN picks p ON p.team_id = t.id
                                       AND p.round = '1'
                                       AND p.season_year >= :anopick
                      WHERE t.league = :liga
                      GROUP BY t.id, t.city, t.name",
        ],
        'tradesenviadas' => [
            'titulo' => 'Trades Enviadas', 'sub' => 'propostas que o time mandou na sprint',
            'alto' => '📤 Mais enviadas', 'baixo' => '🤐 Menos enviadas', 'ordem' => 'desc',
            // Na multi quem propõe é o created_by_team_id — os outros
            // participantes entraram na proposta de alguém, não fizeram uma.
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome,
                             (SELECT COUNT(*) FROM trades tr
                               WHERE tr.from_team_id = t.id AND tr.created_at >= (SELECT COALESCE(MAX(s.start_date), '1900-01-01') FROM sprints s WHERE s.league = :liga AND s.status = 'active'))
                           + (SELECT COUNT(*) FROM multi_trades mt
                               WHERE mt.created_by_team_id = t.id AND mt.created_at >= (SELECT COALESCE(MAX(s.start_date), '1900-01-01') FROM sprints s WHERE s.league = :liga AND s.status = 'active')) AS valor
                      FROM teams t
                      WHERE t.league = :liga",
        ],
        'tradesaceitas' => [
            'titulo' => 'Trades Aceitas', 'sub' => 'trocas fechadas na sprint, de dois times ou mais',
            'alto' => '🤝 Mais aceitas', 'baixo' => '🧊 Menos aceitas', 'ordem' => 'desc',
            // Conta pra TODO time envolvido, quem propôs e quem topou: os dois
            // aceitaram a mesma troca. Na multi, vale pros N participantes.
            // O DISTINCT no mt.id é o que evita contar a mesma multi uma vez
            // por item — uma troca de cinco jogadores viraria cinco trades.
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome,
                             (SELECT COUNT(*) FROM trades tr
                               WHERE tr.status='accepted' AND tr.created_at >= (SELECT COALESCE(MAX(s.start_date), '1900-01-01') FROM sprints s WHERE s.league = :liga AND s.status = 'active')
                                 AND (tr.from_team_id = t.id OR tr.to_team_id = t.id))
                           + (SELECT COUNT(DISTINCT mt.id)
                                FROM multi_trades mt
                                JOIN multi_trade_items mi ON mi.trade_id = mt.id
                               WHERE mt.status='accepted' AND mt.created_at >= (SELECT COALESCE(MAX(s.start_date), '1900-01-01') FROM sprints s WHERE s.league = :liga AND s.status = 'active')
                                 AND (mi.from_team_id = t.id OR mi.to_team_id = t.id)) AS valor
                      FROM teams t
                      WHERE t.league = :liga",
        ],
        'tradesrecusadas' => [
            'titulo' => 'Trades Recusadas', 'sub' => 'propostas rejeitadas na sprint',
            'alto' => '❌ Mais recusadas', 'baixo' => '✅ Menos recusadas', 'ordem' => 'desc',
            // multi_trades não tem status 'rejected' (só pending/accepted/
            // cancelled), então aqui só entram as de dois times mesmo.
            'sql' => "SELECT CONCAT(t.city,' ',t.name) AS nome, COUNT(tr.id) AS valor
                      FROM teams t
                      LEFT JOIN trades tr ON (tr.from_team_id=t.id OR tr.to_team_id=t.id)
                                         AND tr.status='rejected'
                                         AND tr.created_at >= (SELECT COALESCE(MAX(s.start_date), '1900-01-01') FROM sprints s WHERE s.league = :liga AND s.status = 'active')
                      WHERE t.league = :liga
                      GROUP BY t.id, t.city, t.name",
        ],
    ];
}

/**
 * Linhas da conta compartilhada (backend/estatisticas_playoff.php) no formato
 * do bot: 'nome' e 'valor'. Dupla sai com os nomes curtos ("Blues × Heat").
 */
function ebDoMapa(array $linhas, array $def): array
{
    // Sequência e jejum: quem tem zero não é notícia no top 5.
    if (!empty($def['sem_zero'])) $linhas = array_values(array_filter($linhas, fn($l) => (float) $l['count'] > 0));
    $sep = $def['sep'] ?? ' × ';
    return array_map(fn($l) => [
        'nome'  => !empty($def['par']) ? ($l['a'] . $sep . $l['b']) : $l['name'],
        'valor' => $l['count'],
    ], $linhas);
}

/** As linhas de uma estatística, já ordenadas. */
function ebLinhas(PDO $pdo, array $def, string $liga): array
{
    try {
        if (!empty($def['calc']) && str_starts_with($def['calc'], 'ep')) {
            // Conta compartilhada com a página: já vem ordenada do jeito dela,
            // com desempates que o usort lá de baixo desfaria.
            require_once __DIR__ . '/estatisticas_playoff.php';
            return ebDoMapa(($def['calc'])($pdo)[$liga] ?? [], $def);
        }
        if (empty($def['calc'])) {
            // :anopick só é passado pra quem pede — PDO recusa parâmetro que
            // a consulta não usa, então mandar sempre quebraria as outras.
            $params = [':liga' => $liga];
            if (str_contains($def['sql'], ':anopick')) {
                require_once __DIR__ . '/helpers.php';
                $params[':anopick'] = anoDeCorteDasPicks($pdo, $liga);
            }
            $st = $pdo->prepare($def['sql']);
            $st->execute($params);
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
        // /trades e /trocas NÃO entram aqui: os dois são o feed das últimas
        // trocas (wcTrocas), e o switch do whatsapp-comandos pega antes de o
        // catálogo ser consultado. Apelido aqui seria linha morta — e pior,
        // uma que parece funcionar quando se lê este arquivo.
        //
        // A regra do par: nome curto = o que aconteceu; nome com sufixo = o
        // ranking daquele recorte.
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
        'Elenco e draft' => ['elencojovem', 'elencovelho', 'freeagency', 'top5', 'toppicks'],
        'Playoff'        => ['titulos', 'dinastia', 'vice', 'seed', 'idasplayoffs', 'sequencia', 'jejum', '4a0', '0a4', 'jogo7'],
        'Confrontos'     => ['rivalidades', 'dominio', 'duplas', 'unidirecionais'],
        // Só os rankings entram aqui. O /trades e o /trocas são o feed das
        // últimas trocas e vivem no /ajuda, não nesta lista — misturar 'o que
        // aconteceu' com 'quem lidera' foi o que me fez errar antes.
        'Trades'         => ['tradesaceitas', 'tradesenviadas', 'tradesrecusadas', 'parceiros'],
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
