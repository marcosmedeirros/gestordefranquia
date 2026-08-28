<?php
/**
 * API de Draft
 * Gerencia sessões de draft, ordem de picks e seleções
 */

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/push.php';
// Quem escolhe em cada vaga: dono da pick + swap. Ver backend/draft_swaps.php.
require_once __DIR__ . '/../backend/draft_swaps.php';
// Proteção de pick: quem caiu na faixa protegida não passa (só ELITE).
require_once __DIR__ . '/../backend/pick_protection.php';
require_once __DIR__ . '/../backend/loteria_grupos.php';

header('Content-Type: application/json');

/* A SIMULAÇÃO DA LOTERIA É A ÚNICA COISA AQUI QUE DISPENSA LOGIN.
   Ela não escreve nada e não lê nada de ninguém: monta um sorteio a partir
   da classificação da temporada e devolve o resultado pra tela de ensaio,
   que existe pra explicar o modelo a quem ainda vai entrar na liga. O que
   ela mostra é o mesmo que a liga anuncia no comunicado.

   Qualquer outra ação continua exigindo sessão — inclusive sortear e
   aplicar a ordem ao draft de verdade. */
$corpoBruto = file_get_contents('php://input');
$dadosPrevios = $corpoBruto ? json_decode($corpoBruto, true) : null;
$ehSimulacaoLoteria = is_array($dadosPrevios)
    && ($dadosPrevios['action'] ?? '') === 'run_lottery'
    && !empty($dadosPrevios['simulacao']);

if (!$ehSimulacaoLoteria) {
    try {
        requireAuth();
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autorizado']);
        exit;
    }
}

$user = getUserSession() ?: ['id' => 0, 'user_type' => 'visitante'];
$pdo = db();
ensurePlayerRestrictionColumns($pdo);
try { $pdo->exec("ALTER TABLE draft_sessions ADD COLUMN current_pick_started_at DATETIME NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE draft_pool ADD COLUMN pick_hint INT NULL"); } catch (Exception $e) {}
// Relógio da 1ª rodada, agendado pelo admin — antes desse horário (ou se nunca definido),
// autopick continua só o de 30min/fila de sempre; depois dele, vira 5min + fallback pela
// ordem geral (ver ensureRound2DeadlineSet acima pro mesmo padrão aplicado à 2ª rodada).
try { $pdo->exec("ALTER TABLE draft_sessions ADD COLUMN round1_clock_start_at DATETIME NULL"); } catch (Exception $e) {}
// 2ª rodada: mock por pick (substitui o antigo sistema de "ofertas" draft_round2_offers,
// que nunca era resolvido de verdade — ver ensureRound2DeadlineSet/resolveRound2MocksIfDue).
try { $pdo->exec("ALTER TABLE draft_sessions ADD COLUMN round2_mock_deadline DATETIME NULL"); } catch (Exception $e) {}
$pdo->exec("CREATE TABLE IF NOT EXISTS draft_round2_mocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    draft_order_id INT NOT NULL,
    team_id INT NOT NULL,
    player_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_r2mock_pick (draft_order_id),
    FOREIGN KEY (draft_order_id) REFERENCES draft_order(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES draft_pool(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$method = $_SERVER['REQUEST_METHOD'];

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

/**
 * Onde mora a cerimônia enquanto ela acontece.
 *
 * Uma linha por sessão de draft: a ordem que saiu do sorteio e as escolhas
 * já reveladas. Some do caminho do draft de propósito — aplicar a ordem
 * continua sendo o "Confirmar". Isto aqui é só o que está no ar.
 */
function garantirTabelaTransmissao(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS lottery_broadcast (
        draft_session_id INT NOT NULL PRIMARY KEY,
        league VARCHAR(20) NOT NULL,
        ordem LONGTEXT NOT NULL,
        ajustes LONGTEXT NULL,
        reveladas VARCHAR(255) NOT NULL DEFAULT '',
        atualizado_em DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Admin global (user_type='admin') OU admin da liga via league_admins — mesmo critério
// usado em drafts.php (a página) e em api/leilao.php e api/market.php.
$isAdmin = !empty($user['id']) && hasAdminAccess($pdo, (int)$user['id']);

// Função utilitária: compacta posições (1..N) em todas as rodadas, mantendo ordem atual
function recalculateOrderPositions(PDO $pdo, int $draftSessionId): void {
    $stmtRounds = $pdo->prepare('SELECT total_rounds FROM draft_sessions WHERE id = ?');
    $stmtRounds->execute([$draftSessionId]);
    $totalRounds = (int)($stmtRounds->fetchColumn() ?: 0);
    if ($totalRounds < 1) return;

    for ($round = 1; $round <= $totalRounds; $round++) {
        $stmt = $pdo->prepare('SELECT id FROM draft_order WHERE draft_session_id = ? AND round = ? ORDER BY pick_position ASC, id ASC');
        $stmt->execute([$draftSessionId, $round]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pos = 1;
        foreach ($rows as $row) {
            $pdo->prepare('UPDATE draft_order SET pick_position = ? WHERE id = ?')->execute([$pos, $row['id']]);
            $pos++;
        }
    }
}

// Notifica o próximo time que é a vez dele
function notifyNextPick(PDO $pdo, int $teamId, int $round, int $pickPosition): void {
    $stmt = $pdo->prepare('SELECT u.id FROM teams t JOIN users u ON t.user_id = u.id WHERE t.id = ? LIMIT 1');
    $stmt->execute([$teamId]);
    $userId = (int)($stmt->fetchColumn() ?: 0);
    if (!$userId) return;

    sendPushToUser($pdo, $userId, [
        'title'      => '🏀 É a sua vez no Draft!',
        'body'       => "Rodada {$round} · Pick #{$pickPosition} — Você tem 30 min para escolher.",
        'url'        => '/drafts.php',
        'primaryKey' => 'draft_pick_' . $teamId . '_' . $round . '_' . $pickPosition,
    ], 'draft');
}

// Na primeira vez que a sessão chega na rodada 2, liga o relógio de 20min (idempotente —
// só seta se ainda for NULL). Sem cron nesse projeto: essa função é chamada oportunisticamente
// no topo das ações de round2, igual o padrão já usado em resolverLeiloesExpirados (api/leilao.php).
function ensureRound2DeadlineSet(PDO $pdo, int $draftSessionId): void {
    $stmt = $pdo->prepare('SELECT current_round, round2_mock_deadline FROM draft_sessions WHERE id = ? AND status = "in_progress"');
    $stmt->execute([$draftSessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($session && (int)$session['current_round'] === 2 && empty($session['round2_mock_deadline'])) {
        $pdo->prepare('UPDATE draft_sessions SET round2_mock_deadline = DATE_ADD(NOW(), INTERVAL 20 MINUTE) WHERE id = ?')
            ->execute([$draftSessionId]);
    }
}

// Resolve os mocks da rodada 2 quando o prazo vence (ou na hora, se $force — botão "Resolver
// agora" do admin). Processa as picks em ordem de pick_position: quem tem a pick mais alta e
// mandou mock leva o jogador, se ele ainda estiver disponível (não levado por uma pick anterior
// nessa mesma passada). Pick sem mock, ou cujo jogador já foi levado, fica em aberto — o admin
// preenche depois pelo "Preencher pick passada", igual já é feito hoje. No final, o draft é
// marcado concluído (rodada 2 é sempre a última).
function resolveRound2MocksIfDue(PDO $pdo, int $draftSessionId, bool $force = false): void {
    $stmt = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "in_progress"');
    $stmt->execute([$draftSessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session || (int)$session['current_round'] !== 2) return;
    if (!$force) {
        if (empty($session['round2_mock_deadline'])) return;
        if (strtotime($session['round2_mock_deadline']) > time()) return;
    }

    $stmtSeasonNum = $pdo->prepare('SELECT season_number FROM seasons WHERE id = ?');
    $stmtSeasonNum->execute([$session['season_id']]);
    $draftSeasonNumber = (int)($stmtSeasonNum->fetchColumn() ?: 1);

    $stmtRoundSize = $pdo->prepare('SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = 2');
    $stmtRoundSize->execute([$draftSessionId]);
    $roundSize = (int)$stmtRoundSize->fetchColumn();

    $stmtPicks = $pdo->prepare('SELECT * FROM draft_order WHERE draft_session_id = ? AND round = 2 AND picked_player_id IS NULL ORDER BY pick_position ASC');
    $stmtPicks->execute([$draftSessionId]);
    $picks = $stmtPicks->fetchAll(PDO::FETCH_ASSOC);

    $stmtMocks = $pdo->prepare('SELECT draft_order_id, player_id FROM draft_round2_mocks WHERE draft_order_id IN (SELECT id FROM draft_order WHERE draft_session_id = ? AND round = 2 AND picked_player_id IS NULL)');
    $stmtMocks->execute([$draftSessionId]);
    $mockByPick = [];
    foreach ($stmtMocks->fetchAll(PDO::FETCH_ASSOC) as $m) { $mockByPick[(int)$m['draft_order_id']] = (int)$m['player_id']; }

    $pdo->beginTransaction();
    try {
        $claimed = [];
        foreach ($picks as $pick) {
            $playerId = $mockByPick[(int)$pick['id']] ?? null;
            if (!$playerId || isset($claimed[$playerId])) continue;

            $stmtPlayer = $pdo->prepare('SELECT * FROM draft_pool WHERE id = ? AND draft_status = "available"');
            $stmtPlayer->execute([$playerId]);
            $player = $stmtPlayer->fetch(PDO::FETCH_ASSOC);
            if (!$player) continue;

            $targetTeamId = (int)$pick['team_id'];
            $pickNumber = (($pick['round'] - 1) * $roundSize) + $pick['pick_position'];

            $pdo->prepare('UPDATE draft_order SET picked_player_id = ?, picked_at = NOW() WHERE id = ?')
                ->execute([$playerId, (int)$pick['id']]);
            $pdo->prepare('UPDATE draft_pool SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ? WHERE id = ?')
                ->execute([$targetTeamId, $pickNumber, $playerId]);

            $playerName = trim((string)($player['name'] ?? ''));
            $stmtExisting = $pdo->prepare('SELECT id FROM players WHERE team_id = ? AND name = ? LIMIT 1');
            $stmtExisting->execute([$targetTeamId, $playerName]);
            if (!$stmtExisting->fetchColumn()) {
                try {
                    $pdo->prepare('INSERT INTO players (team_id, drafted_by_team_id, drafted_season_number, draft_round, draft_pick_position, name, position, age, ovr, role, available_for_trade) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "Banco", 0)')
                        ->execute([$targetTeamId, $targetTeamId, $draftSeasonNumber, (int)$pick['round'], (int)$pick['pick_position'], $playerName, $player['position'], (int)$player['age'], (int)$player['ovr']]);
                } catch (Exception $e) {
                    if (!str_contains($e->getMessage(), 'unique_player_per_team')) throw $e;
                }
            }

            $claimed[$playerId] = true;
        }

        $pdo->prepare('UPDATE draft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
            ->execute([$draftSessionId]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('resolveRound2MocksIfDue: ' . $e->getMessage());
    }
}

// ========== GET ==========
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'active_draft';

    switch ($action) {
        /* O QUE ESTÁ NO AR AGORA. Consultado de poucos em poucos segundos
           por quem está assistindo, então devolve o mínimo: a ordem, o que
           já foi revelado e a hora da última mudança — é por ela que a tela
           decide se tem algo novo antes de redesenhar qualquer coisa.

           Aberto a qualquer um da plataforma: assistir à cerimônia é o
           ponto. Publicar e revelar é que são do admin. */
        case 'lottery_transmissao': {
            $sid = (int)($_GET['draft_session_id'] ?? 0);
            if (!$sid) { echo json_encode(['success' => true, 'no_ar' => false]); exit; }
            garantirTabelaTransmissao($pdo);
            $st = $pdo->prepare('SELECT ordem, ajustes, reveladas, atualizado_em
                                   FROM lottery_broadcast WHERE draft_session_id = ?');
            $st->execute([$sid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success' => true, 'no_ar' => false]); exit; }
            echo json_encode([
                'success'   => true,
                'no_ar'     => true,
                'ordem'     => json_decode($row['ordem'], true) ?: [],
                'ajustes'   => json_decode((string)$row['ajustes'], true) ?: [],
                'reveladas' => array_values(array_filter(array_map('intval', explode(',', (string)$row['reveladas'])))),
                'em'        => $row['atualizado_em'],
            ]);
            break;
        }

        /* CONFERIR AS PICKS DA ORDEM — só leitura.
           Responde "as picks que comprei estão comigo?" sem que perguntar
           mude nada: compara, vaga por vaga, quem está escolhendo com quem
           deveria pela tabela de picks, já com o swap resolvido. */
        case 'conferir_picks': {
            $sid = (int)($_GET['draft_session_id'] ?? 0);
            if (!$sid) { echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']); exit; }

            $conf = draftConferirOrdem($pdo, $sid);

            // Ids viram nomes: quem lê é uma pessoa.
            $ids = [];
            foreach ($conf['divergencias'] as $d) { $ids[] = $d['origem']; $ids[] = $d['esta_com']; $ids[] = $d['deveria']; }
            foreach ($conf['swaps'] as $s) { $ids[] = $s['melhor_dono']; $ids[] = $s['melhor_de']; $ids[] = $s['pior_dono']; $ids[] = $s['pior_de']; }
            foreach ($conf['sem_pick'] as $s) $ids[] = $s['origem'];
            foreach ($conf['origem_repetida'] as $o) $ids[] = $o['time'];
            foreach ($conf['protecoes'] as $p) { $ids[] = $p['origem']; $ids[] = $p['dono']; }
            $nomes = draftNomesDosTimes($pdo, $ids);
            $nome = fn($id) => $nomes[(int)$id] ?? ('Time ' . (int)$id);

            $conf['divergencias'] = array_map(fn($d) => $d + [
                'origem_nome'   => $nome($d['origem']),
                'esta_com_nome' => $nome($d['esta_com']),
                'deveria_nome'  => $nome($d['deveria']),
            ], $conf['divergencias']);
            $conf['swaps'] = array_map(fn($s) => $s + [
                'melhor_dono_nome' => $nome($s['melhor_dono']),
                'melhor_de_nome'   => $nome($s['melhor_de']),
                'pior_dono_nome'   => $nome($s['pior_dono']),
                'pior_de_nome'     => $nome($s['pior_de']),
            ], $conf['swaps']);
            $conf['sem_pick']        = array_map(fn($s) => $s + ['origem_nome' => $nome($s['origem'])], $conf['sem_pick']);
            $conf['origem_repetida'] = array_map(fn($o) => $o + ['time_nome' => $nome($o['time'])], $conf['origem_repetida']);
            $conf['protecoes'] = array_map(fn($p) => $p + [
                'origem_nome' => $nome($p['origem']),
                'dono_nome'   => $nome($p['dono']),
            ], $conf['protecoes']);

            echo json_encode(['success' => true] + $conf);
            break;
        }

        // Buscar draft ativo da liga
        case 'active_draft':
            $league = $_GET['league'] ?? ($team['league'] ?? null);
            if (!$league) {
                echo json_encode(['success' => false, 'error' => 'Liga não especificada']);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT ds.*, s.season_number, s.year
                 FROM draft_sessions ds
                 INNER JOIN seasons s ON ds.season_id = s.id
                 WHERE ds.league = ? AND ds.status IN ('setup', 'in_progress')
                   AND s.sprint_id = ?
                 ORDER BY s.season_number DESC, ds.created_at DESC LIMIT 1"
            );
            $stmt->execute([$league, loteriaSprintAtiva($pdo, $league) ?? 0]);
            $draft = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($draft && !empty($draft['current_pick_started_at'])) {
                $draft['pick_deadline_ts'] = strtotime($draft['current_pick_started_at']) + 1800;
            }
            // Relógio da 1ª rodada (round1_clock_start_at): só devolve o prazo calculado da
            // pick atual (5min) quando o relógio já estiver armado — mesma fórmula usada em
            // check_autopick/runAutopickForSession (maior entre início da pick e hora marcada).
            if ($draft && !empty($draft['round1_clock_start_at'])) {
                $clockStartTs = strtotime($draft['round1_clock_start_at']);
                if (time() >= $clockStartTs && !empty($draft['current_pick_started_at'])) {
                    $effectiveStart = max(strtotime($draft['current_pick_started_at']), $clockStartTs);
                    $draft['round1_pick_deadline_ts'] = $effectiveStart + 300;
                }
            }

            echo json_encode(['success' => true, 'draft' => $draft]);
            break;

        /**
         * O MOCK DA TEMPORADA, enquanto o draft ainda está sendo configurado.
         *
         * Entre definir a classe e rodar a loteria existe uma espera em que a
         * página de drafts não tinha nada pra mostrar — e é justamente a
         * semana em que a liga mais quer olhar pro draft. Aqui saem as duas
         * metades do que ela quer ver:
         *
         *  - os CALOUROS na ordem da classe (ordem, nome e posição). Sem OVR e
         *    sem idade de propósito: todo mundo entra no pool com 60/18, então
         *    mostrar esses números seria mostrar uma informação falsa — o que
         *    o jogador é de verdade só se sabe no 2K.
         *
         *  - a ORDEM PROJETADA, no modelo do 2K: o pior time do power ranking
         *    pega a primeira. É projeção, não sorteio: a loteria de verdade
         *    sai da campanha e ainda não rodou.
         *
         * E ao lado de cada pick, quem é o DONO dela hoje — pick trocada
         * aparece com o time de origem e com quem ficou, que é a informação
         * que some quando se olha só o power ranking.
         */
        case 'draft_preview':
            $league = $_GET['league'] ?? ($team['league'] ?? null);
            if (!$league) {
                echo json_encode(['success' => false, 'error' => 'Liga não especificada']);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT ds.id, ds.season_id, ds.status, ds.total_rounds, s.season_number, s.year
                 FROM draft_sessions ds
                 INNER JOIN seasons s ON ds.season_id = s.id
                 WHERE ds.league = ? AND ds.status IN ('setup', 'in_progress')
                   AND s.sprint_id = ?
                 ORDER BY s.season_number DESC, ds.created_at DESC LIMIT 1"
            );
            $stmt->execute([$league, loteriaSprintAtiva($pdo, $league) ?? 0]);
            $sessao = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sessao) {
                echo json_encode(['success' => true, 'pool' => [], 'projecao' => [], 'session' => null]);
                exit;
            }

            // Os calouros na ordem da classe. pick_hint é a ordem que veio do
            // cadastro; quem não tem vai pro fim, por nome, pra lista nunca
            // sair embaralhada de um carregamento pro outro.
            $stmt = $pdo->prepare(
                "SELECT id, name, position, pick_hint
                 FROM draft_pool WHERE season_id = ?
                 ORDER BY COALESCE(pick_hint, 999999) ASC, name ASC"
            );
            $stmt->execute([(int)$sessao['season_id']]);
            $pool = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $p) {
                $pool[] = [
                    'ordem'    => $i + 1,
                    'name'     => $p['name'],
                    'position' => $p['position'],
                ];
            }

            // A ordem projetada: power ranking de trás pra frente.
            require_once __DIR__ . '/../backend/power_ranking.php';
            $forca = fbaPowerRanking($pdo, $league);
            $projecao = [];
            if ($forca) {
                $doDraft = array_reverse($forca);

                // Dono atual de cada pick desta temporada, por rodada. A chave
                // é o time de ORIGEM: é a pick "do" time, mesmo estando com
                // outro.
                $dono = ['1' => [], '2' => []];
                try {
                    // Swap e proteção vêm junto: uma pick trocada não diz a
                    // mesma coisa que uma pick trocada COM swap, e quem olha o
                    // mock precisa ver a condição antes de contar com ela.
                    // Os selos saem de protecaoSelosLista, que é a mesma fonte
                    // das trades e do WhatsApp — repetir a regra aqui era como
                    // a proteção já sumiu de uma tela antes.
                    $sp = $pdo->prepare(
                        "SELECT p.round, p.original_team_id, p.team_id,
                                p.swap_type, p.protection, p.protection_resultado,
                                CONCAT(COALESCE(t.city,''), ' ', COALESCE(t.name,'')) AS dono_nome,
                                t.photo_url AS dono_logo,
                                CONCAT(COALESCE(sp2.city,''), ' ', COALESCE(sp2.name,'')) AS swap_com
                         FROM picks p
                         LEFT JOIN teams t ON t.id = p.team_id
                         LEFT JOIN picks pp ON pp.id = p.swap_pair_pick_id
                         LEFT JOIN teams sp2 ON sp2.id = pp.original_team_id
                         WHERE p.season_id = ? AND p.round IN ('1','2')"
                    );
                    $sp->execute([(int)$sessao['season_id']]);
                    foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $dono[(string)$r['round']][(int)$r['original_team_id']] = [
                            'team_id'  => (int)$r['team_id'],
                            'nome'     => trim((string)$r['dono_nome']),
                            'logo'     => $r['dono_logo'] ?? null,
                            'swap_com' => trim((string)($r['swap_com'] ?? '')),
                            'selos'    => protecaoSelosLista($r),
                        ];
                    }
                } catch (Throwable $e) {
                    // Temporada sem picks cadastradas: a projeção sai só com o
                    // time de origem, que ainda é a metade útil.
                }

                // As duas rodadas, na mesma ordem — a classe costuma ter mais
                // calouros do que times, e com só a 1ª metade da lista ficava
                // sem time nenhum ao lado.
                $totalRodadas = max(1, min(2, (int)($sessao['total_rounds'] ?? 2)));
                for ($rodada = 1; $rodada <= $totalRodadas; $rodada++) {
                    foreach ($doDraft as $i => $t) {
                        $d = $dono[(string)$rodada][$t['team_id']] ?? null;
                        $projecao[] = [
                            'pick'          => count($projecao) + 1,
                            'rodada'        => $rodada,
                            'pick_na_rodada'=> $i + 1,
                            'team_id'       => $t['team_id'],
                            'team_name'     => $t['team_name'],
                            'team_photo'    => $t['team_photo'],
                            'owner_name'    => $t['owner_name'],
                            'power_posicao' => $t['posicao'],
                            'dono_team_id'  => $d['team_id'] ?? $t['team_id'],
                            'dono_nome'     => ($d['nome'] ?? '') !== '' ? $d['nome'] : $t['team_name'],
                            // O escudo é o do DONO: é o time que vai escolher.
                            'dono_logo'     => ($d && $d['team_id'] !== $t['team_id'])
                                               ? ($d['logo'] ?? null) : $t['team_photo'],
                            'swap_com'      => $d['swap_com'] ?? '',
                            'selos'         => $d['selos'] ?? [],
                            'trocada'       => $d && $d['team_id'] !== $t['team_id'],
                        ];
                    }
                }
            }

            echo json_encode(['success' => true, 'session' => $sessao,
                              'pool' => $pool, 'projecao' => $projecao]);
            break;

        // Buscar ordem de draft e status das picks
        case 'draft_order':
            $draftSessionId = $_GET['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT do.*, 
                        t.city as team_city, t.name as team_name, t.photo_url as team_photo,
                        ot.city as original_city, ot.name as original_name,
                        tf.city as traded_from_city, tf.name as traded_from_name,
                        dp.name as player_name, dp.position as player_position, dp.ovr as player_ovr
                 FROM draft_order do
                 INNER JOIN teams t ON do.team_id = t.id
                 INNER JOIN teams ot ON do.original_team_id = ot.id
                 LEFT JOIN teams tf ON do.traded_from_team_id = tf.id
                 LEFT JOIN draft_pool dp ON do.picked_player_id = dp.id
                 WHERE do.draft_session_id = ?
                 ORDER BY do.round ASC, do.pick_position ASC"
            );
            $stmt->execute([$draftSessionId]);
            $order = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmtSession = $pdo->prepare(
                "SELECT ds.*, s.season_number, s.year
                 FROM draft_sessions ds
                 INNER JOIN seasons s ON ds.season_id = s.id
                 WHERE ds.id = ?"
            );
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'session' => $session,
                'order' => $order
            ]);
            break;

        case 'league_teams':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Sem permissão']);
                exit;
            }

            $league = $_GET['league'] ?? ($team['league'] ?? null);
            if (!$league) {
                echo json_encode(['success' => false, 'error' => 'Liga não especificada']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT id, city, name FROM teams WHERE league = ? ORDER BY city ASC, name ASC');
            $stmt->execute([$league]);
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'teams' => $teams]);
            break;

        // Buscar jogadores disponíveis para draft
        case 'available_players':
            $seasonId = $_GET['season_id'] ?? null;
            if (!$seasonId) {
                echo json_encode(['success' => false, 'error' => 'season_id obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT * FROM draft_pool
                 WHERE season_id = ? AND draft_status = 'available'
                 ORDER BY COALESCE(pick_hint, 999999) ASC, ovr DESC, name ASC"
            );
            $stmt->execute([$seasonId]);
            $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'players' => $players]);
            break;

        // "Ver Jogadores" (board completo): TODOS os jogadores da temporada,
        // disponíveis ou já draftados, na mesma ordem fixa — ao contrário de
        // available_players, não filtra por status, pra quem já saiu continuar
        // aparecendo (cinza, no front) na posição/ordem dele.
        case 'board_players':
            $seasonId = $_GET['season_id'] ?? null;
            if (!$seasonId) {
                echo json_encode(['success' => false, 'error' => 'season_id obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT * FROM draft_pool
                 WHERE season_id = ?
                 ORDER BY COALESCE(pick_hint, 999999) ASC, ovr DESC, name ASC"
            );
            $stmt->execute([$seasonId]);
            $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'players' => $players]);
            break;

        // Verificar se é a vez do time
        case 'my_turn':
            $draftSessionId = $_GET['draft_session_id'] ?? null;
            if (!$draftSessionId || !$team) {
                echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "in_progress"');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                echo json_encode(['success' => true, 'is_my_turn' => false, 'reason' => 'Draft não está em andamento']);
                exit;
            }

            $stmtPick = $pdo->prepare(
                "SELECT do.*, t.city, t.name
                 FROM draft_order do
                 INNER JOIN teams t ON do.team_id = t.id
                 WHERE do.draft_session_id = ?
                   AND do.round = ?
                   AND do.pick_position = ?
                   AND do.picked_player_id IS NULL"
            );
            $stmtPick->execute([$draftSessionId, $session['current_round'], $session['current_pick']]);
            $currentPick = $stmtPick->fetch(PDO::FETCH_ASSOC);

            $isMyTurn = $currentPick && (int)$currentPick['team_id'] === (int)$team['id'];

            echo json_encode([
                'success' => true,
                'is_my_turn' => $isMyTurn,
                'current_pick' => $currentPick,
                'session' => $session
            ]);
            break;

        // Buscar histórico de draft de uma temporada
        case 'draft_history':
            $seasonId = $_GET['season_id'] ?? null;
            $league = $_GET['league'] ?? ($team['league'] ?? null);
            if (!$seasonId && !$league) {
                echo json_encode(['success' => false, 'error' => 'season_id ou league obrigatório']);
                exit;
            }

            if ($seasonId) {
                $stmt = $pdo->prepare(
                    "SELECT s.*, ds.status as draft_status, ds.id as draft_session_id
                     FROM seasons s
                     LEFT JOIN draft_sessions ds ON ds.season_id = s.id
                     WHERE s.id = ?"
                );
                $stmt->execute([$seasonId]);
                $season = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$season) {
                    echo json_encode(['success' => false, 'error' => 'Temporada não encontrada']);
                    exit;
                }

                if (!empty($season['draft_order_snapshot'])) {
                    $snapshot = json_decode($season['draft_order_snapshot'], true);
                    echo json_encode([
                        'success' => true,
                        'season' => $season,
                        'draft_order' => $snapshot,
                        'from_snapshot' => true
                    ]);
                    exit;
                }

                $stmtSession = $pdo->prepare('SELECT id FROM draft_sessions WHERE season_id = ?');
                $stmtSession->execute([$seasonId]);
                $sessionData = $stmtSession->fetch();

                if ($sessionData) {
                    $stmtOrder = $pdo->prepare(
                        "SELECT do.*, 
                                t.city as team_city, t.name as team_name, t.photo_url as team_photo,
                                ot.city as original_city, ot.name as original_name,
                                tf.city as traded_from_city, tf.name as traded_from_name,
                                dp.name as player_name, dp.position as player_position, dp.ovr as player_ovr
                         FROM draft_order do
                         INNER JOIN teams t ON do.team_id = t.id
                         INNER JOIN teams ot ON do.original_team_id = ot.id
                         LEFT JOIN teams tf ON do.traded_from_team_id = tf.id
                         LEFT JOIN draft_pool dp ON do.picked_player_id = dp.id
                         WHERE do.draft_session_id = ?
                         ORDER BY do.round ASC, do.pick_position ASC"
                    );
                    $stmtOrder->execute([$sessionData['id']]);
                    $order = $stmtOrder->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode([
                        'success' => true,
                        'season' => $season,
                        'draft_order' => $order,
                        'draft_session_id' => $sessionData['id'],
                        'from_snapshot' => false
                    ]);
                    exit;
                }

                echo json_encode(['success' => true, 'season' => $season, 'draft_order' => [], 'from_snapshot' => false]);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT s.id, s.season_number, s.year, s.league, s.status,
                        CASE WHEN s.draft_order_snapshot IS NOT NULL THEN 1 ELSE 0 END as has_snapshot,
                        ds.status as draft_status, ds.id as draft_session_id
                 FROM seasons s
                 LEFT JOIN draft_sessions ds ON ds.season_id = s.id
                 WHERE s.league = ?
                   AND s.sprint_id = (SELECT id FROM sprints WHERE league = ? AND status = 'active' ORDER BY id DESC LIMIT 1)
                 ORDER BY s.id DESC"
            );
            $stmt->execute([$league, $league]);
            $seasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'seasons' => $seasons]);
            break;

        // Jogadores disponíveis para preencher draft passado
        case 'available_players_for_past_draft':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $_GET['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT season_id FROM draft_sessions WHERE id = ?');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão de draft não encontrada']);
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT * FROM draft_pool 
                 WHERE season_id = ? AND draft_status = 'available'
                 ORDER BY ovr DESC, name ASC"
            );
            $stmt->execute([$session['season_id']]);
            $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'players' => $players]);
            break;

        // Board da 2ª rodada: todas as picks (round=2) da sessão, ordenadas por pick_position.
        // Cada time só vê o jogador do PRÓPRIO mock (has_mock indica se tem, sem revelar quem,
        // pras picks dos outros times); admin vê o mock de todo mundo. Resolve sozinho (lazy,
        // sem cron) se o prazo de 20min já passou.
        case 'round2_board':
            $draftSessionId = $_GET['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }
            ensureRound2DeadlineSet($pdo, (int)$draftSessionId);
            resolveRound2MocksIfDue($pdo, (int)$draftSessionId);

            $stmtDeadline = $pdo->prepare('SELECT round2_mock_deadline FROM draft_sessions WHERE id = ?');
            $stmtDeadline->execute([(int)$draftSessionId]);
            $deadline = $stmtDeadline->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT o.id AS draft_order_id, o.team_id, o.pick_position, o.picked_player_id,
                        t.city AS team_city, t.name AS team_name,
                        m.player_id AS mock_player_id, dp.name AS mock_player_name,
                        dp.position AS mock_player_position, dp.ovr AS mock_player_ovr
                 FROM draft_order o
                 INNER JOIN teams t ON o.team_id = t.id
                 LEFT JOIN draft_round2_mocks m ON m.draft_order_id = o.id
                 LEFT JOIN draft_pool dp ON dp.id = m.player_id
                 WHERE o.draft_session_id = ? AND o.round = 2
                 ORDER BY o.pick_position ASC"
            );
            $stmt->execute([(int)$draftSessionId]);
            // Quantas vagas tem a 1ª rodada: é o quanto somar pra converter a
            // posição da 2ª (que recomeça do 1) na numeração corrida, que é
            // como a liga fala — "escolha 43", não "11ª da segunda".
            $stmtVagas = $pdo->prepare('SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = 1');
            $stmtVagas->execute([(int)$draftSessionId]);
            $vagasR1 = (int)$stmtVagas->fetchColumn();

            $myTeamId = $team['id'] ?? null;
            $picks = array_map(function ($r) use ($isAdmin, $myTeamId, $vagasR1) {
                $isOwn = $myTeamId && (int)$r['team_id'] === (int)$myTeamId;
                $canSeeMock = $isAdmin || $isOwn;
                return [
                    'draft_order_id' => (int)$r['draft_order_id'],
                    'team_id' => (int)$r['team_id'],
                    'team_name' => trim($r['team_city'] . ' ' . $r['team_name']),
                    'pick_position' => (int)$r['pick_position'],
                    'pick_overall' => $vagasR1 + (int)$r['pick_position'],
                    'picked_player_id' => $r['picked_player_id'] !== null ? (int)$r['picked_player_id'] : null,
                    'is_own' => (bool)$isOwn,
                    'has_mock' => $r['mock_player_id'] !== null,
                    'mock_player' => ($canSeeMock && $r['mock_player_id'] !== null) ? [
                        'id' => (int)$r['mock_player_id'],
                        'name' => $r['mock_player_name'],
                        'position' => $r['mock_player_position'],
                        'ovr' => (int)$r['mock_player_ovr'],
                    ] : null,
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));

            echo json_encode(['success' => true, 'picks' => $picks, 'round2_mock_deadline' => $deadline]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

// ========== POST ==========
if ($method === 'POST') {
    // O corpo ja foi lido no topo (pra decidir a autenticacao); reler o
    // stream depois de consumido nao e garantido em todo servidor.
    $data = $dadosPrevios;
    $action = $data['action'] ?? '';

    switch ($action) {
        // ADMIN: Criar sessão de draft
        case 'create_session':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $seasonId = $data['season_id'] ?? null;
            if (!$seasonId) {
                echo json_encode(['success' => false, 'error' => 'season_id obrigatório']);
                exit;
            }

            $stmtSeason = $pdo->prepare('SELECT league FROM seasons WHERE id = ?');
            $stmtSeason->execute([$seasonId]);
            $seasonData = $stmtSeason->fetch();
            if (!$seasonData) {
                echo json_encode(['success' => false, 'error' => 'Temporada não encontrada']);
                exit;
            }

            $league = $seasonData['league'];

            $stmtCheck = $pdo->prepare('SELECT id FROM draft_sessions WHERE season_id = ?');
            $stmtCheck->execute([$seasonId]);
            if ($stmtCheck->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Já existe uma sessão de draft para esta temporada']);
                exit;
            }

            $stmt = $pdo->prepare('INSERT INTO draft_sessions (season_id, league, total_rounds) VALUES (?, ?, 2)');
            $stmt->execute([$seasonId, $league]);
            $draftSessionId = $pdo->lastInsertId();

            echo json_encode(['success' => true, 'draft_session_id' => $draftSessionId]);
            break;

        // ADMIN: Adicionar time à ordem de draft (sem "via", permite repetição)
        case 'add_to_order':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            $teamId = $data['team_id'] ?? null;
            if (!$draftSessionId || !$teamId) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "setup"');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já iniciada']);
                exit;
            }

            $stmtCount = $pdo->prepare('SELECT COALESCE(MAX(pick_position), 0) as max_pos FROM draft_order WHERE draft_session_id = ? AND round = 1');
            $stmtCount->execute([$draftSessionId]);
            $maxPos = (int)($stmtCount->fetch()['max_pos'] ?? 0);
            $newPos = $maxPos + 1;

            try {
                $pdo->beginTransaction();
                for ($round = 1; $round <= (int)$session['total_rounds']; $round++) {
                    $pdo->prepare(
                        'INSERT INTO draft_order (draft_session_id, team_id, original_team_id, pick_position, round, traded_from_team_id)
                         VALUES (?, ?, ?, ?, ?, NULL)'
                    )->execute([
                        (int)$draftSessionId,
                        (int)$teamId,
                        (int)$teamId,
                        (int)$newPos,
                        (int)$round,
                    ]);
                }
                $pdo->commit();

                recalculateOrderPositions($pdo, (int)$draftSessionId);

                echo json_encode(['success' => true, 'message' => 'Time adicionado']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro']);
            }
            break;

        // ADMIN: Remover time da ordem (por posição em todas as rodadas)
        case 'remove_from_order':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $pickId = $data['pick_id'] ?? null;
            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$pickId || !$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtPick = $pdo->prepare('SELECT pick_position FROM draft_order WHERE id = ?');
            $stmtPick->execute([$pickId]);
            $pick = $stmtPick->fetch();
            if (!$pick) {
                echo json_encode(['success' => false, 'error' => 'Pick não encontrada']);
                exit;
            }

            $pdo->prepare('DELETE FROM draft_order WHERE draft_session_id = ? AND pick_position = ?')->execute([(int)$draftSessionId, (int)$pick['pick_position']]);

            recalculateOrderPositions($pdo, (int)$draftSessionId);

            echo json_encode(['success' => true, 'message' => 'Time removido']);
            break;

        // ADMIN: Limpar ordem
        case 'clear_order':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }

            $pdo->prepare('DELETE FROM draft_order WHERE draft_session_id = ?')->execute([(int)$draftSessionId]);
            echo json_encode(['success' => true, 'message' => 'Ordem limpa']);
            break;

        // ADMIN: Excluir sessão de draft
        case 'delete_session':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM draft_order WHERE draft_session_id = ?')->execute([(int)$draftSessionId]);
                $pdo->prepare('DELETE FROM draft_sessions WHERE id = ?')->execute([(int)$draftSessionId]);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Sessão excluída']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro']);
            }
            break;

        // ADMIN: Calcula (sem gravar) a ordem de draft via loteria ponderada — só ELITE.
        // Usa a classificação da temporada ELITE anterior à do draft_session informado.
        // Não escreve nada; o admin confirma chamando 'set_draft_order' com o resultado.
        case 'run_lottery':
            /* A PRÉVIA É PÚBLICA, O SORTEIO NÃO.
               Quem está na loteria quer ver a própria chance antes da
               cerimônia, e essa informação é a mesma que o comunicado da liga
               anuncia — esconder dos GMs só fazia com que a pergunta chegasse
               por mensagem. Sortear, esse sim, continua sendo do admin: é o
               ato que define o draft. */
            $apenasPreview = !empty($data['preview']);

            /* SIMULAÇÃO: sortear sem que o sorteio conte.
               A cerimônia inteira roda dentro do navegador de quem pediu, e
               este endpoint não escreve uma linha sequer no banco — quem
               aplica a ordem ao draft é o "Confirmar", que é outra ação e
               continua sendo do admin. Por isso a simulação pode ficar
               aberta: ela não tem como mudar nada pra ninguém.

               Serve pra ensaiar a cerimônia antes do dia e pra qualquer GM
               entender o modelo mexendo nele, em vez de ler a regra. */
            $simulacao = !empty($data['simulacao']);
            if (!$isAdmin && !$apenasPreview && !$simulacao) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            // Ordem e grupos provisórios valem na prévia e na simulação —
            // no sorteio que vale, manda o que está gravado.
            $aceitaProvisorio = $apenasPreview || $simulacao;

            // Ligas que o GM logado administra (a loteria é por liga do GM).
            $myLeagues = array_values(array_intersect(['ELITE', 'NEXT', 'RISE', 'ROOKIE'], getAdminLeagues($pdo, (int)$user['id'])));

            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }

            $stmtDS = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ?');
            $stmtDS->execute([(int)$draftSessionId]);
            $lotterySession = $stmtDS->fetch();
            if (!$lotterySession) {
                echo json_encode(['success' => false, 'error' => 'Sessão de draft não encontrada']);
                exit;
            }
            if (!in_array($lotterySession['league'], ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
                echo json_encode(['success' => false, 'error' => 'A loteria está disponível para ELITE, NEXT, RISE e ROOKIE']);
                exit;
            }
            // O GM só pode SORTEAR a loteria da(s) liga(s) que administra.
            // Ver a prévia é outra coisa: basta ser da liga.
            if (!$apenasPreview && !$simulacao && !in_array($lotterySession['league'], $myLeagues, true)) {
                echo json_encode(['success' => false, 'error' => 'Você não administra a liga desta sessão de draft']);
                exit;
            }

            $stmtUpcoming = $pdo->prepare('SELECT season_number FROM seasons WHERE id = ?');
            $stmtUpcoming->execute([(int)$lotterySession['season_id']]);
            $upcomingSeasonNumber = $stmtUpcoming->fetchColumn();
            if ($upcomingSeasonNumber === false) {
                echo json_encode(['success' => false, 'error' => 'Temporada do draft não encontrada']);
                exit;
            }
            /* A CLASSIFICAÇÃO QUE MANDA É A ÚLTIMA LANÇADA — não "a temporada
               anterior à do draft".

               Antes a conta era season_number - 1, e isso amarrava a loteria
               ao AVANÇO da temporada: enquanto a liga não avançasse, a sessão
               de draft ainda era da temporada corrente e a busca caía na
               classificação de dois anos atrás, ou em nenhuma. Na prática só
               dava pra sortear depois de avançar, que é um passo que acontece
               dias depois de a campanha terminar.

               Agora vale a temporada mais recente da liga QUE TENHA posição
               registrada, com o número menor ou igual ao do draft. Os dois
               fluxos passam a funcionar:
                 · avançou     → a sessão é da N+1 e a última lançada é a N;
                 · não avançou → a sessão é da N   e a última lançada é a N.

               O teto (<=) existe pra uma loteria antiga, reaberta, não
               enxergar a campanha de uma temporada que veio depois dela. */
            /* A classificação vem da SPRINT ATUAL. A liga recomeça a cada
               sprint com outros times e outra numeração — buscar só pelo
               número da temporada alcançava a campanha de uma sprint
               encerrada e montava a loteria em cima dela. */
            $stmtStandingsSeason = $pdo->prepare("
                SELECT s.id, s.season_number
                  FROM seasons s
                 WHERE s.league = ?
                   AND s.sprint_id = ?
                   AND s.season_number <= ?
                   AND EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id)
              ORDER BY s.season_number DESC, s.id DESC
                 LIMIT 1");
            $stmtStandingsSeason->execute([
                $lotterySession['league'],
                loteriaSprintAtiva($pdo, $lotterySession['league']) ?? 0,
                (int)$upcomingSeasonNumber,
            ]);
            $standingsRow = $stmtStandingsSeason->fetch(PDO::FETCH_ASSOC);
            if (!$standingsRow) {
                echo json_encode(['success' => false,
                    'error' => 'A ' . $lotterySession['league'] . ' ainda não tem posições registradas. '
                             . 'Lance a classificação no registro de pontuação da temporada.']);
                exit;
            }
            $standingsSeasonId     = (int)$standingsRow['id'];
            $standingsSeasonNumber = (int)$standingsRow['season_number'];

            loteriaGarantirColunas($pdo);

            // Posição é por conferência (1..16 em cada). COALESCE cobre standings legados
            // sem conferência, caindo na conferência atual do time.
            $stmtST = $pdo->prepare('
                SELECT ss.team_id, ss.position, COALESCE(ss.conference, t.conference) AS conference,
                       ss.wins, ss.points_for, ss.points_against, ss.overall_position,
                       ss.draft_tail_position, ss.lottery_group,
                       CONCAT(t.city," ",t.name) AS team_name, t.photo_url
                FROM season_standings ss
                JOIN teams t ON t.id = ss.team_id
                WHERE ss.season_id = ?
                ORDER BY ss.position ASC
            ');
            $stmtST->execute([(int)$standingsSeasonId]);
            $allStandings = $stmtST->fetchAll(PDO::FETCH_ASSOC);
            if (!$allStandings) {
                // Não fala mais em "temporada anterior": a classificação usada
                // é a última lançada, que pode ser a da própria temporada do
                // draft quando a liga ainda não avançou.
                echo json_encode(['success' => false, 'error' => 'Não há "Posições" registradas na temporada nº ' . $standingsSeasonNumber . '.']);
                exit;
            }

            // Playoff = top 8 de CADA conferência (conta as duas). Elegíveis à loteria = o resto.
            $PLAYOFF_PER_CONF = 8;
            $byConf = [];
            foreach ($allStandings as $row) {
                $conf = $row['conference'] ?: 'UNICA';
                $byConf[$conf][] = $row;
            }
            foreach ($byConf as $conf => &$list) {
                usort($list, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
            }
            unset($list);

            $teamNames = [];
            $teamConf = [];
            $teamPhoto = [];
            $teamPos = [];    // posição dentro da conferência (1 = melhor)
            $teamWins = [];   // vitórias — base antiga do ranking de "pior campanha"
            $teamOverall = []; // ordem geral declarada (17º em diante); null quando não existe
            $teamTail = [];   // ordem de escolha entre os classificados (1 = pica primeiro); null quando não existe
            $teamGrupo = [];  // grupo de loteria declarado pelo admin (1..4); ausente quando não declarado
            $teamPdiff = [];  // saldo de pontos, desempate
            $eligible = [];
            $playoffRows = [];
            foreach ($byConf as $conf => $list) {
                $cut = min($PLAYOFF_PER_CONF, count($list));
                foreach ($list as $idx => $row) {
                    $tid = (int)$row['team_id'];
                    $teamNames[$tid] = $row['team_name'];
                    $teamConf[$tid] = $conf === 'UNICA' ? '' : $conf;
                    $teamPhoto[$tid] = (!empty($row['photo_url']) && trim($row['photo_url']) !== '') ? $row['photo_url'] : '/img/default-team.png';
                    $teamPos[$tid] = (int)$row['position'];
                    $teamWins[$tid] = isset($row['wins']) ? (int)$row['wins'] : 0;
                    $teamOverall[$tid] = isset($row['overall_position']) && $row['overall_position'] !== null
                        ? (int)$row['overall_position'] : null;
                    $teamTail[$tid] = isset($row['draft_tail_position']) && $row['draft_tail_position'] !== null
                        ? (int)$row['draft_tail_position'] : null;
                    if (isset($row['lottery_group']) && $row['lottery_group'] !== null) {
                        $teamGrupo[$tid] = (int)$row['lottery_group'];
                    }
                    $teamPdiff[$tid] = (int)($row['points_for'] ?? 0) - (int)($row['points_against'] ?? 0);
                    if ($idx < $cut) {
                        $playoffRows[] = $row;
                    } else {
                        $eligible[] = $row;
                    }
                }
            }
            $playoffTeamIds = array_map(fn($r) => (int)$r['team_id'], $playoffRows);

            /* ORDEM PROVISÓRIA DA PRÉVIA.
               O admin reordena o quadro na tela da loteria e precisa ver o
               efeito nos grupos antes de gravar — mover um time do 4º pro 2º
               pior o tira do grupo de 3 bolinhas e põe no de 2, e isso tem
               que aparecer na hora.

               A lista chega na ordem do quadro (pior campanha primeiro) e
               vira uma ordem geral em memória, seguindo a convenção do
               registro da temporada: número MAIOR = campanha pior. Só os
               elegíveis entram, e só se vierem todos — uma lista pela metade
               montaria grupos a partir de um retrato incompleto.

               Vale SÓ na prévia. No sorteio de verdade manda o que está
               gravado: senão uma aba esquecida aberta sortearia por uma
               ordem que ninguém confirmou. */
            if ($aceitaProvisorio && !empty($data['ordem']) && is_array($data['ordem'])) {
                $idsElegiveis = array_flip(array_map(fn($r) => (int)$r['team_id'], $eligible));
                $ordemProv = [];
                foreach ($data['ordem'] as $tidProv) {
                    $tidProv = (int)$tidProv;
                    if (isset($idsElegiveis[$tidProv]) && !in_array($tidProv, $ordemProv, true)) {
                        $ordemProv[] = $tidProv;
                    }
                }
                if (count($ordemProv) === count($eligible)) {
                    $topoProv = count($ordemProv);
                    foreach ($ordemProv as $iProv => $tidProv) {
                        $teamOverall[$tidProv] = $topoProv - $iProv;
                    }
                }
            }

            // Pool de loteria: pior pro melhor (posição maior = pior), interleaviando conferências.
            usort($eligible, fn($a, $b) => (int)$b['position'] <=> (int)$a['position']);

            // Cauda de playoff no draft: quem foi menos longe pica antes; campeão pica por último.
            // Usa playoff_results (o quão longe foi) quando existir; desempata por pior posição.
            $poScore = ['first_round' => 1, 'second_round' => 2, 'conference_final' => 3, 'runner_up' => 4, 'champion' => 5];
            $poRank = [];
            $stmtPO = $pdo->prepare('SELECT team_id, position FROM playoff_results WHERE season_id = ?');
            $stmtPO->execute([(int)$standingsSeasonId]);
            foreach ($stmtPO->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $poRank[(int)$r['team_id']] = $poScore[$r['position']] ?? 0;
            }
            /* ORDEM PROVISÓRIA DA CAUDA (só prévia), mesma ideia da loteria.
               Aqui o motivo é outro: entre dois times que caíram na mesma
               fase, playoff_results não distingue quem foi mais longe, e a
               diferença entre a pick 31 e a 32 vira um empate resolvido por
               posição de conferência. O admin desempata na mão. */
            if ($aceitaProvisorio && !empty($data['ordem_playoff']) && is_array($data['ordem_playoff'])) {
                $idsPlayoff = array_flip($playoffTeamIds);
                $caudaProv = [];
                foreach ($data['ordem_playoff'] as $tidCauda) {
                    $tidCauda = (int)$tidCauda;
                    if (isset($idsPlayoff[$tidCauda]) && !in_array($tidCauda, $caudaProv, true)) {
                        $caudaProv[] = $tidCauda;
                    }
                }
                if (count($caudaProv) === count($playoffRows)) {
                    foreach ($caudaProv as $iCauda => $tidCauda) $teamTail[$tidCauda] = $iCauda + 1;
                }
            }

            /* A ordem declarada manda quando existe: ela é a única que separa
               dois times que playoff_results empata. Sem ela, vale o critério
               de sempre — quem foi menos longe pica antes, campeão por último. */
            usort($playoffRows, function ($a, $b) use ($poRank, $teamTail) {
                $ta = $teamTail[(int)$a['team_id']] ?? null;
                $tb = $teamTail[(int)$b['team_id']] ?? null;
                if ($ta !== null && $tb !== null && $ta !== $tb) return $ta <=> $tb;

                $ra = $poRank[(int)$a['team_id']] ?? 0;
                $rb = $poRank[(int)$b['team_id']] ?? 0;
                if ($ra !== $rb) return $ra <=> $rb;
                return (int)$b['position'] <=> (int)$a['position'];
            });
            $playoffTail = array_map(fn($r) => (int)$r['team_id'], $playoffRows);

            $n = count($eligible);
            $adjustments = [];
            $balls = [];

            /* ── Loteria 3-2-1 ──
               Quem ficou fora do playoff entra no sorteio, dividido em quatro
               grupos com 2, 3, 2 e 1 bolinhas. Quem monta os grupos e quem
               calcula as chances é backend/loteria_grupos.php — a mesma regra
               que o /loteria do WhatsApp responde, pra não existir uma chance
               no site e outra no grupo. */
            $GROUP_META = LOTERIA_GRUPOS_META;

            /* "PIOR CAMPANHA" DA LIGA.
               A ordem geral declarada no registro da temporada manda: ela é
               uma lista única do 17º em diante, então diz sem ambiguidade
               quem terminou atrás de quem — inclusive entre times de
               conferências diferentes. Número MAIOR = pior campanha.

               Só quando ela não existe (temporada antiga, ou classificação
               lançada antes deste campo) é que vale o critério velho:
               vitórias, saldo e posição de conferência. E ele é frágil de
               propósito conhecido — desde que vitórias saíram do cadastro,
               ficam todas em zero e o desempate cai na posição, que empata
               o 15º de um lado com o 15º do outro. Foi exatamente isso que
               colocou dois times de mesma colocação em grupos de bolinhas
               diferentes. */
            $badness = function ($aTid, $bTid) use ($teamWins, $teamPdiff, $teamPos, $teamOverall) {
                $oa = $teamOverall[$aTid] ?? null;
                $ob = $teamOverall[$bTid] ?? null;
                if ($oa !== null && $ob !== null && $oa !== $ob) return $ob <=> $oa;

                if ($teamWins[$aTid] !== $teamWins[$bTid]) return $teamWins[$aTid] <=> $teamWins[$bTid];
                if ($teamPdiff[$aTid] !== $teamPdiff[$bTid]) return $teamPdiff[$aTid] <=> $teamPdiff[$bTid];
                return $teamPos[$bTid] <=> $teamPos[$aTid]; // posição maior = pior
            };

            $eligibleIds = array_map(fn($r) => (int)$r['team_id'], $eligible);

            /* GRUPO DECLARADO PROVISÓRIO (só prévia), como a ordem.
               Trocar a tag de um time muda o tamanho do grupo, o total de
               bolinhas e portanto a chance de TODO MUNDO — então o card
               precisa ser recalculado no mesmo instante, e não depois de
               gravar. */
            if ($aceitaProvisorio && isset($data['grupos']) && is_array($data['grupos'])) {
                $idsElegiveisG = array_flip($eligibleIds);
                foreach ($data['grupos'] as $tidG => $gG) {
                    $tidG = (int)$tidG;
                    $gG   = (int)$gG;
                    if (!isset($idsElegiveisG[$tidG])) continue;
                    if ($gG >= 1 && $gG <= 4) $teamGrupo[$tidG] = $gG;
                    else unset($teamGrupo[$tidG]);   // 0 = volta pro automático
                }
            }

            $declarados = array_intersect_key($teamGrupo, array_flip($eligibleIds));
            $groupOf = loteriaDistribuirGrupos($eligibleIds, $teamPos, $declarados, $badness);

            // Os 3 piores, que o piso de proteção usa mais abaixo.
            $group1 = array_values(array_filter($eligibleIds, fn($t) => ($groupOf[$t] ?? 2) === 1));

            foreach ($eligibleIds as $tid) {
                $g = $groupOf[$tid] ?? 2;
                $balls[$tid] = $GROUP_META[$g]['balls'];
            }
            $totalBalls = array_sum($balls);
            // As chances saem da urna que existe, não de números fixos por grupo.
            $odds = loteriaOdds($balls);

            /* MODO PRÉVIA: monta tudo e NÃO sorteia.
               A tela da loteria precisa mostrar quem entra, em que grupo e
               com quantas bolinhas, ANTES de alguém apertar o botão. Chamar o
               sorteio pra isso seria pior que inútil: cada vez que a página
               abrisse sairia uma ordem diferente, e a de verdade seria só a
               última — o admin veria um resultado que não vale.

               Na prévia a ordem exibida é a NATURAL (pior campanha primeiro),
               que é a que existe antes de qualquer bolinha rolar.

               $apenasPreview já foi decidido lá em cima, junto da permissão:
               é ele que separa quem pode olhar de quem pode sortear. */

            /* ESCOLHAS QUE JÁ SAÍRAM.
               Quando parte da cerimônia já aconteceu — e o registro dela veio
               do ajuste manual, porque um sorteio se perdeu antes de existir
               transmissão —, esses times não voltam pra urna. O sorteio cobre
               só as posições que sobraram, entre quem sobrou.

               Chega como {posição: team_id}; posição 1 é a primeira escolha. */
            $jaSaiu = [];
            if (!empty($data['ja_saiu']) && is_array($data['ja_saiu'])) {
                foreach ($data['ja_saiu'] as $posFixa => $tidFixo) {
                    $posFixa = (int)$posFixa;
                    $tidFixo = (int)$tidFixo;
                    if ($posFixa >= 1 && isset($balls[$tidFixo])) $jaSaiu[$posFixa] = $tidFixo;
                }
            }

            // Sorteio ponderado SEM reposição para TODAS as posições da loteria (não só o top-4).
            $pool = $balls;
            foreach ($jaSaiu as $tidFixo) unset($pool[$tidFixo]);   // fora da urna
            $drawOrder = [];
            while (!$apenasPreview && !empty($pool)) {
                $sum = array_sum($pool);
                $rand = mt_rand(1, max(1, $sum));
                $cum = 0;
                $winner = null;
                foreach ($pool as $tid => $w) {
                    $cum += $w;
                    if ($rand <= $cum) { $winner = (int)$tid; break; }
                }
                if ($winner === null) { $winner = (int)array_key_last($pool); }
                $drawOrder[] = $winner;
                unset($pool[$winner]);
            }

            // Piso de proteção: os 3 piores (G1) não podem cair além da pick 12.
            // Se algum caiu, troca com a vaga mais funda dentro do top-12 que não seja de outro G1.
            $FLOOR_CAP = 11; // índice 0-based da pick 12

            /* A ORDEM DE ATENDIMENTO É SORTEADA, e não é detalhe.
               Quem é atendido primeiro fica com a vaga mais funda do top-12,
               porque a busca começa na 12 e sobe. Percorrendo $group1 na
               ordem em que ele vem — pior campanha primeiro —, o pior time
               da liga terminava na pick 12 em 32% dos sorteios contra 20% do
               terceiro pior, com exatamente as mesmas bolinhas. A proteção
               punia mais quem ela existe pra proteger.

               Sorteando a ordem, os três dividem as vagas 10, 11 e 12 em pé
               de igualdade. */
            $ordemProtecao = $group1;
            shuffle($ordemProtecao);
            foreach ($ordemProtecao as $tid) {
                $idx = array_search($tid, $drawOrder, true);
                if ($idx !== false && $idx > $FLOOR_CAP) {
                    for ($j = $FLOOR_CAP; $j >= 0; $j--) {
                        if (!in_array($drawOrder[$j], $group1, true)) {
                            $swap = $drawOrder[$j];
                            $drawOrder[$j] = $tid;
                            $drawOrder[$idx] = $swap;
                            $adjustments[] = ($teamNames[$tid] ?? "Time #$tid") . ' foi protegido(a) pelo piso: como está entre os 3 piores, não pode cair além da pick 12.';
                            break;
                        }
                    }
                }
            }

            /* Os que já saíram voltam pras posições onde saíram, e o que foi
               sorteado agora preenche o que sobrou, na ordem em que saiu da
               urna. Feito depois do piso: quem já estava numa posição não é
               protegido de novo — aquela escolha já aconteceu. */
            if ($jaSaiu && !$apenasPreview) {
                $sorteados = $drawOrder;
                $drawOrder = [];
                $total = count($eligibleIds);
                for ($p = 1; $p <= $total; $p++) {
                    $drawOrder[] = $jaSaiu[$p] ?? array_shift($sorteados);
                }
                $drawOrder = array_values(array_filter($drawOrder, fn($t) => $t !== null));
            }

            // Na prévia, o que aparece é a ordem natural — pior campanha
            // primeiro. É o retrato de antes do sorteio, e é o que faz o
            // "subiu/caiu" ter contra o que ser medido depois.
            if ($apenasPreview) {
                $drawOrder = $eligibleIds;
                usort($drawOrder, $badness);
            }

            $lotteryPortion = $drawOrder;
            $finalOrderIds = array_merge($lotteryPortion, $playoffTail);

            // Ordem "natural" (sem sorteio): loteria em ordem de pior campanha, depois a cauda de playoff.
            // Serve pra medir quanto cada time subiu/caiu com a loteria.
            $naturalLottery = $eligibleIds;
            usort($naturalLottery, $badness);
            $naturalOrderIds = array_merge($naturalLottery, $playoffTail);
            $expectedSlot = [];
            foreach ($naturalOrderIds as $i => $tid) {
                $expectedSlot[(int)$tid] = $i + 1;
            }

            $eligibleCount = count($eligible);

            // ── Dono atual das picks de 1ª rodada do ano do draft (swaps/trocas) ──
            // O slot é definido pela campanha do time de origem, mas quem escolhe é
            // o dono atual da pick. Convenção NBA: "Dono (via ORI)".
            $stmtY = $pdo->prepare('SELECT s.season_number, s.year, sp.start_year
                                    FROM seasons s LEFT JOIN sprints sp ON sp.id = s.sprint_id
                                    WHERE s.id = ?');
            $stmtY->execute([(int)$lotterySession['season_id']]);
            $yrow = $stmtY->fetch(PDO::FETCH_ASSOC);
            $draftYear = 0;
            if ($yrow) {
                $draftYear = (!empty($yrow['start_year']) && isset($yrow['season_number']))
                    ? (int)$yrow['start_year'] + (int)$yrow['season_number'] - 1
                    : (int)($yrow['year'] ?? 0);
            }
            $pickOwner = [];
            if ($draftYear > 0) {
                $stmtPk = $pdo->prepare('SELECT p.original_team_id, p.team_id AS owner_id,
                                                CONCAT(t.city," ",t.name) AS owner_name,
                                                t.photo_url AS owner_photo, t.conference AS owner_conf
                                         FROM picks p JOIN teams t ON t.id = p.team_id
                                         WHERE p.season_year = ? AND p.round = 1');
                $stmtPk->execute([$draftYear]);
                foreach ($stmtPk->fetchAll(PDO::FETCH_ASSOC) as $pk) {
                    $pickOwner[(int)$pk['original_team_id']] = $pk;
                }
            }
            // sigla de 3 letras a partir da cidade (ex.: Oakland -> OAK)
            $abbr3 = function ($full) {
                $city = trim((string)$full);
                $clean = preg_replace('/[^A-Za-zÀ-ÿ]/u', '', $city);
                $clean = iconv('UTF-8', 'ASCII//TRANSLIT', $clean) ?: $clean;
                return mb_strtoupper(mb_substr($clean, 0, 3));
            };
            $stmtCity = $pdo->prepare('SELECT city FROM teams WHERE id = ?');

            $orderOut = [];
            foreach ($finalOrderIds as $i => $tid) {
                $tid = (int)$tid;
                $actual = $i + 1;
                $expected = $expectedSlot[$tid] ?? $actual;
                $isPlayoff = in_array($tid, $playoffTeamIds, true);
                // Quem escolhe: dono atual da pick (pode ser outro time por troca/swap)
                $own = $pickOwner[$tid] ?? null;
                $isSwap = $own && (int)$own['owner_id'] !== $tid;
                $pickerId    = $isSwap ? (int)$own['owner_id'] : $tid;
                $pickerName  = $isSwap ? $own['owner_name'] : ($teamNames[$tid] ?? "Time #$tid");
                $pickerPhoto = $isSwap
                    ? ((!empty($own['owner_photo']) && trim($own['owner_photo']) !== '') ? $own['owner_photo'] : '/img/default-team.png')
                    : ($teamPhoto[$tid] ?? '/img/default-team.png');
                $pickerConf  = $isSwap ? ($own['owner_conf'] ?? '') : ($teamConf[$tid] ?? '');
                $originAbbr = '';
                if ($isSwap) {
                    $stmtCity->execute([$tid]);
                    $originAbbr = $abbr3($stmtCity->fetchColumn() ?: ($teamNames[$tid] ?? ''));
                }

                $orderOut[] = [
                    'position' => $actual,
                    'team_id' => $pickerId,
                    'team_name' => $pickerName,
                    'conference' => $pickerConf,
                    'photo_url' => $pickerPhoto,
                    'source' => $isPlayoff ? 'playoff' : 'lottery',
                    'group' => $isPlayoff ? 0 : ($groupOf[$tid] ?? 0),
                    'expected_slot' => $expected,
                    'delta' => $isPlayoff ? 0 : ($expected - $actual), // >0 subiu, <0 caiu
                    // swap: o slot veio da campanha do time de origem
                    'is_swap' => (bool)$isSwap,
                    'origin_team_id' => $tid,
                    'origin_name' => $teamNames[$tid] ?? '',
                    'origin_abbr' => $originAbbr,
                ];
            }

            // Quem passou na frente de cada time que caiu: times naturalmente ATRÁS
            // (expected_slot maior) que terminaram numa pick melhor (position menor).
            // A quantidade bate exatamente com o tamanho da queda.
            foreach ($orderOut as &$e) {
                $e['passed_by'] = [];
                if ($e['source'] === 'playoff' || $e['delta'] >= 0) continue;
                foreach ($orderOut as $o) {
                    if ($o['position'] === $e['position']) continue;
                    if ($o['position'] < $e['position'] && $o['expected_slot'] > $e['expected_slot']) {
                        $e['passed_by'][] = [
                            'team_id' => $o['team_id'],
                            'team_name' => $o['team_name'],
                            'photo_url' => $o['photo_url'],
                        ];
                    }
                }
            }
            unset($e);
            $ballsOut = [];
            foreach ($eligible as $row) {
                $tid = (int)$row['team_id'];
                $g = $groupOf[$tid] ?? 2;
                $meta = $GROUP_META[$g];
                $b = $balls[$tid] ?? 0;
                $ballsOut[] = [
                    'team_id' => $tid,
                    'team_name' => $teamNames[$tid] ?? "Time #$tid",
                    'conference' => $teamConf[$tid] ?? '',
                    'photo_url' => $teamPhoto[$tid] ?? '/img/default-team.png',
                    'position_anterior' => (int)$row['position'],
                    'group' => $g,
                    'group_label' => $meta['label'],
                    'balls' => $b,
                    // A chance da pick nº 1 é a única faixa que soma 100% entre
                    // os times — é a que o comunicado da liga anuncia.
                    'top1_pct' => $odds['top1'][$tid] ?? 0,
                    'top3_pct' => $odds['top3'][$tid] ?? 0,
                    'top5_pct' => $odds['top5'][$tid] ?? 0,
                    'odds_pct' => $totalBalls > 0 ? round(($b / $totalBalls) * 100, 2) : 0,
                    // Marcado pelo admin (fato de jogo) ou deduzido da ordem.
                    'group_declarado' => isset($declarados[$tid]),
                ];
            }
            // Ordena por grupo e, dentro do grupo, da pior pra melhor campanha.
            usort($ballsOut, function ($a, $b) use ($badness) {
                if ($a['group'] !== $b['group']) return $a['group'] <=> $b['group'];
                return $badness($a['team_id'], $b['team_id']);
            });

            echo json_encode([
                'success' => true,
                'draft_session_id' => (int)$draftSessionId,
                'standings_season_id' => (int)$standingsSeasonId,
                'standings_season_number' => $standingsSeasonNumber,
                'eligible_count' => $eligibleCount,
                'playoff_count' => count($playoffTail),
                'balls' => $ballsOut,
                'total_balls' => $totalBalls,
                /* A chance de cada time em CADA pick. É o que fecha 100% nos
                   dois sentidos — por time e por escolha —, e é simulada
                   porque inclui o piso de proteção, que remaneja a ordem
                   depois do sorteio. Cacheada por urna. */
                'matriz' => loteriaMatriz($balls, $group1, $FLOOR_CAP),
                'order' => $orderOut,
                'adjustments' => $adjustments,
                'group_meta' => $GROUP_META,
                // A tela precisa saber que este resultado NAO e um sorteio:
                // mostrar "ordem definida" numa previa faria a pessoa achar
                // que ja aconteceu.
                'preview' => $apenasPreview,
            ]);
            break;

        /* ADMIN: grava a ordem de campanha dos times de loteria.
           É a mesma lista que o card Pontuação preenche (17º em diante) —
           aqui ela é editada de dentro da cerimônia, onde o erro aparece:
           o admin vê o time no grupo errado no quadro e corrige ali.
           Grava só os elegíveis; quem foi pro playoff não é reordenado. */
        /* ── A CERIMÔNIA AO VIVO ────────────────────────────────────────
           O sorteio não escrevia nada: a ordem saía do servidor e vivia no
           navegador de quem apertou o botão. Funciona pra quem conduz e não
           existe pra mais ninguém — a liga inteira ficava olhando uma tela
           parada enquanto o admin revelava.

           Aqui a ordem passa a existir fora daquele navegador, junto do que
           já foi revelado. Não é o draft: aplicar a ordem continua sendo o
           "Confirmar", que é outra ação. Isto é só o retrato do que está
           acontecendo, pra quem está assistindo poder ver. */
        case 'lottery_transmitir': {
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            $sid   = (int)($data['draft_session_id'] ?? 0);
            $ordem = $data['ordem'] ?? null;
            if (!$sid || !is_array($ordem) || !$ordem) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }
            $stLiga = $pdo->prepare('SELECT league FROM draft_sessions WHERE id = ?');
            $stLiga->execute([$sid]);
            $ligaSessao = $stLiga->fetchColumn();
            if (!$ligaSessao) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada']);
                exit;
            }
            $ehAdminGeral = ($user['user_type'] ?? '') === 'admin';
            if (!$ehAdminGeral && !in_array($ligaSessao, getAdminLeagues($pdo, (int)$user['id']), true)) {
                echo json_encode(['success' => false, 'error' => 'Você não administra esta liga']);
                exit;
            }

            garantirTabelaTransmissao($pdo);
            // Sortear de novo substitui o que estava no ar, e zera o que já
            // tinha sido revelado: é outra cerimônia.
            $pdo->prepare(
                'INSERT INTO lottery_broadcast (draft_session_id, league, ordem, ajustes, reveladas, atualizado_em)
                 VALUES (?, ?, ?, ?, "", NOW())
                 ON DUPLICATE KEY UPDATE ordem = VALUES(ordem), ajustes = VALUES(ajustes),
                                         reveladas = "", atualizado_em = NOW()'
            )->execute([
                $sid, $ligaSessao,
                json_encode($ordem, JSON_UNESCAPED_UNICODE),
                json_encode($data['ajustes'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'lottery_revelar': {
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            $sid = (int)($data['draft_session_id'] ?? 0);
            $pos = (int)($data['position'] ?? 0);
            if (!$sid || $pos < 1) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }
            garantirTabelaTransmissao($pdo);
            $st = $pdo->prepare('SELECT reveladas FROM lottery_broadcast WHERE draft_session_id = ?');
            $st->execute([$sid]);
            $atual = $st->fetchColumn();
            if ($atual === false) {
                echo json_encode(['success' => false, 'error' => 'Não há cerimônia no ar para esta sessão']);
                exit;
            }
            $lista = array_filter(array_map('intval', explode(',', (string)$atual)));
            if (!in_array($pos, $lista, true)) $lista[] = $pos;
            $pdo->prepare('UPDATE lottery_broadcast SET reveladas = ?, atualizado_em = NOW() WHERE draft_session_id = ?')
                ->execute([implode(',', $lista), $sid]);
            echo json_encode(['success' => true, 'reveladas' => array_values($lista)]);
            break;
        }

        case 'save_lottery_order':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            $seasonIdOrdem = (int)($data['season_id'] ?? 0);
            $ordemTimes    = is_array($data['ordem'] ?? null) ? $data['ordem'] : [];
            $ordemCauda    = is_array($data['ordem_playoff'] ?? null) ? $data['ordem_playoff'] : [];
            $gruposMarcados = is_array($data['grupos'] ?? null) ? $data['grupos'] : [];
            if (!$seasonIdOrdem || (count($ordemTimes) < 2 && count($ordemCauda) < 2 && !$gruposMarcados)) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtLiga = $pdo->prepare('SELECT league FROM seasons WHERE id = ?');
            $stmtLiga->execute([$seasonIdOrdem]);
            $ligaOrdem = $stmtLiga->fetchColumn();
            if (!$ligaOrdem) {
                echo json_encode(['success' => false, 'error' => 'Temporada não encontrada']);
                exit;
            }
            if (!in_array($ligaOrdem, getAdminLeagues($pdo, (int)$user['id']), true)) {
                echo json_encode(['success' => false, 'error' => 'Você não administra a ' . $ligaOrdem]);
                exit;
            }

            // Só entram times que estão mesmo na classificação desta temporada.
            $stmtVal = $pdo->prepare('SELECT team_id FROM season_standings WHERE season_id = ?');
            $stmtVal->execute([$seasonIdOrdem]);
            $idsValidos = array_flip(array_map('intval', $stmtVal->fetchAll(PDO::FETCH_COLUMN)));
            $limpar = function (array $lista) use ($idsValidos) {
                $saida = [];
                foreach ($lista as $tidOrdem) {
                    $tidOrdem = (int)$tidOrdem;
                    if (isset($idsValidos[$tidOrdem]) && !in_array($tidOrdem, $saida, true)) {
                        $saida[] = $tidOrdem;
                    }
                }
                return $saida;
            };
            $ordemLimpa = $limpar($ordemTimes);
            $caudaLimpa = $limpar($ordemCauda);
            if (count($ordemLimpa) !== count($ordemTimes) || count($caudaLimpa) !== count($ordemCauda)) {
                echo json_encode(['success' => false, 'error' => 'A ordem tem times repetidos ou que não estão na classificação desta temporada.']);
                exit;
            }
            // Um time não pode estar nos dois lados: ou entrou na loteria, ou foi pro playoff.
            if (array_intersect($ordemLimpa, $caudaLimpa)) {
                echo json_encode(['success' => false, 'error' => 'Um mesmo time apareceu na loteria e no playoff.']);
                exit;
            }

            /* As colunas nasceram depois da tabela, e CREATE TABLE IF NOT
               EXISTS não mexe em tabela existente — bancos antigos chegam
               aqui sem elas. O erro de "já existe" é o caso normal. */
            try { $pdo->exec('ALTER TABLE season_standings ADD COLUMN overall_position INT NULL'); } catch (Throwable $e) { /* já existe */ }
            try { $pdo->exec('ALTER TABLE season_standings ADD COLUMN draft_tail_position INT NULL'); } catch (Throwable $e) { /* já existe */ }

            /* Duas listas, dois significados — e é por isso que não moram na
               mesma coluna.

               `overall_position` é CAMPANHA: convenção do card Pontuação, os
               16 classificados de 1 a 16 e quem ficou de fora de 17 em
               diante. A lista chega do pior pro melhor, então o primeiro
               leva o número mais alto.

               `draft_tail_position` é ORDEM DE ESCOLHA entre os
               classificados, que não é campanha nenhuma: sai de quão longe o
               time foi nos playoffs, e o campeão pica por último mesmo tendo
               sido o melhor. Gravar isso como colocação geral faria o
               campeão virar o 1º da liga na loteria do ano seguinte. */
            $topoSalvar = 16 + count($ordemLimpa);
            try {
                $pdo->beginTransaction();
                $stmtUpd = $pdo->prepare('UPDATE season_standings SET overall_position = ? WHERE season_id = ? AND team_id = ?');
                foreach ($ordemLimpa as $iSalvar => $tidOrdem) {
                    $stmtUpd->execute([$topoSalvar - $iSalvar, $seasonIdOrdem, $tidOrdem]);
                }
                $stmtCauda = $pdo->prepare('UPDATE season_standings SET draft_tail_position = ? WHERE season_id = ? AND team_id = ?');
                foreach ($caudaLimpa as $iSalvar => $tidOrdem) {
                    $stmtCauda->execute([$iSalvar + 1, $seasonIdOrdem, $tidOrdem]);
                }

                /* O GRUPO DECLARADO. Vem sempre completo — inclusive os times
                   que voltaram pro automático, como NULL —, porque a lista
                   parcial não tem como dizer "este aqui eu desmarquei". */
                if ($gruposMarcados) {
                    $stmtGrupo = $pdo->prepare('UPDATE season_standings SET lottery_group = ? WHERE season_id = ? AND team_id = ?');
                    foreach ($gruposMarcados as $tidGrupo => $gGrupo) {
                        $tidGrupo = (int)$tidGrupo;
                        if (!isset($idsValidos[$tidGrupo])) continue;
                        $gGrupo = (int)$gGrupo;
                        $stmtGrupo->execute([($gGrupo >= 1 && $gGrupo <= 4) ? $gGrupo : null, $seasonIdOrdem, $tidGrupo]);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Não foi possível gravar a ordem.']);
                exit;
            }

            echo json_encode(['success' => true, 'total' => count($ordemLimpa) + count($caudaLimpa)]);
            break;

        // ADMIN: Definir ordem completa (sem "via", permite repetição, sem snake)
        case 'set_draft_order':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            $teamOrder = $data['team_order'] ?? [];
            if (!$draftSessionId || empty($teamOrder) || !is_array($teamOrder)) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "setup"');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já iniciada']);
                exit;
            }
            // O GM só aplica ordem na(s) liga(s) que administra.
            $myLeagues = array_values(array_intersect(['ELITE', 'NEXT', 'RISE', 'ROOKIE'], getAdminLeagues($pdo, (int)$user['id'])));
            if (!in_array($session['league'], $myLeagues, true)) {
                echo json_encode(['success' => false, 'error' => 'Você não administra a liga desta sessão de draft']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM draft_order WHERE draft_session_id = ?')->execute([(int)$draftSessionId]);

                for ($round = 1; $round <= (int)$session['total_rounds']; $round++) {
                    foreach (array_values($teamOrder) as $position => $teamIdInOrder) {
                        $pdo->prepare(
                            'INSERT INTO draft_order (draft_session_id, team_id, original_team_id, pick_position, round, traded_from_team_id)
                             VALUES (?, ?, ?, ?, ?, ?)'
                        )->execute([
                            (int)$draftSessionId,
                            (int)$teamIdInOrder,
                            (int)$teamIdInOrder,
                            (int)($position + 1),
                            (int)$round,
                            null
                        ]);
                    }
                }

                $pdo->commit();
                recalculateOrderPositions($pdo, (int)$draftSessionId);

                // As picks protegidas se resolvem AQUI, antes de dizer quem
                // escolhe: agora as posições existem, então dá pra saber se
                // cada protegida caiu na faixa. Quem não passou muda de dono
                // nesta hora, e é esse dono novo que a sincronização abaixo
                // tem que enxergar — na ordem inversa, a ordem sairia com o
                // dono errado até alguém reaplicar.
                $protecoes = protecaoResolverNoDraft($pdo, (int)$draftSessionId);

                // A ordem acabou de ser gravada com team_id = time de origem,
                // ou seja, ignorando quem comprou pick numa troca. Aqui ela
                // passa a dizer quem escolhe de verdade — dono atual da pick,
                // e depois o swap trocando as vagas entre os dois donos.
                $sinc = draftSincronizarOrdem($pdo, (int)$draftSessionId);

                // O resumo do que a loteria decidiu além da ordem: as picks
                // protegidas que passaram ou não, e onde cada swap parou.
                // Vai com NOME de time, não id: quem lê é uma pessoa, e é a
                // única hora em que o desfecho faz sentido — depois, a ordem
                // só mostra o resultado, sem dizer que houve um acordo.
                $ids = [];
                foreach ($protecoes as $p) { $ids[] = $p['de']; $ids[] = $p['para']; }
                foreach ($sinc['pares'] ?? [] as $p) {
                    $ids[] = $p['time_melhor']; $ids[] = $p['time_pior'];
                    $ids[] = $p['vaga_melhor_de']; $ids[] = $p['vaga_pior_de'];
                }
                $nomes = draftNomesDosTimes($pdo, $ids);
                $nome = fn($id) => $nomes[(int)$id] ?? ('Time ' . (int)$id);

                $eventos = [];
                foreach ($protecoes as $p) {
                    $passou = $p['resultado'] === 'passou';
                    $eventos[] = [
                        'tipo' => 'protecao',
                        'passou' => $passou,
                        'texto' => sprintf(
                            $passou
                                ? 'Pick %d do %s caiu em %dº — fora da proteção %s, vai pro %s.'
                                : 'Pick %d do %s caiu em %dº — dentro da proteção %s, NÃO passa: fica com o %s.',
                            $p['ano'], $nome($p['de']), $p['posicao'],
                            protecaoRotulo($p['protecao']),
                            $passou ? $nome($p['para']) : $nome($p['de'])),
                        'extra' => (!$passou && $p['lastro'])
                            ? sprintf('O %s leva a pick de %d do %s no lugar.',
                                      $nome($p['para']), $p['ano'] + 1, $nome($p['de']))
                            : ((!$passou && !$p['lastro'])
                                ? 'ATENÇÃO: o time não tem a pick do ano seguinte — a dívida ficou sem pagamento.'
                                : ''),
                    ];
                }
                foreach ($sinc['pares'] ?? [] as $p) {
                    $eventos[] = [
                        'tipo' => 'swap',
                        'passou' => true,
                        'texto' => sprintf('Swap entre %s (%dº) e %s (%dº): o %s escolhe em %dº e o %s em %dº.',
                            $nome($p['vaga_melhor_de']), $p['pos_melhor'],
                            $nome($p['vaga_pior_de']), $p['pos_pior'],
                            $nome($p['time_melhor']), $p['pos_melhor'],
                            $nome($p['time_pior']), $p['pos_pior']),
                        'extra' => '',
                    ];
                }

                echo json_encode(['success' => true, 'message' => 'Ordem definida com sucesso',
                                  'ajustes' => $sinc, 'protecoes' => $protecoes,
                                  'eventos' => $eventos]);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[draft/apply_order] ' . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Erro ao definir ordem']);
            }
            break;

        // JOGADOR/ADMIN: Trocar pick em andamento
        case 'trade_pick':
            $draftSessionId = $data['draft_session_id'] ?? null;
            $pickId = $data['pick_id'] ?? null;
            $toTeamId = $data['to_team_id'] ?? null;

            if (!$draftSessionId || !$pickId || !$toTeamId) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ?');
            $stmtSession->execute([(int)$draftSessionId]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada']);
                exit;
            }

            if (($session['status'] ?? '') !== 'in_progress') {
                echo json_encode(['success' => false, 'error' => 'Só é possível trocar pick com draft em andamento']);
                exit;
            }

            $stmtPick = $pdo->prepare('SELECT * FROM draft_order WHERE id = ? AND draft_session_id = ?');
            $stmtPick->execute([(int)$pickId, (int)$draftSessionId]);
            $pick = $stmtPick->fetch(PDO::FETCH_ASSOC);
            if (!$pick) {
                echo json_encode(['success' => false, 'error' => 'Pick não encontrada']);
                exit;
            }

            if (!empty($pick['picked_player_id'])) {
                echo json_encode(['success' => false, 'error' => 'Essa pick já foi utilizada']);
                exit;
            }

            $fromTeamId = (int)$pick['team_id'];
            $toTeamId = (int)$toTeamId;
            if ($fromTeamId === $toTeamId) {
                echo json_encode(['success' => false, 'error' => 'A pick já pertence a esse time']);
                exit;
            }

            if (!$isAdmin) {
                if (!$team || (int)$team['id'] !== $fromTeamId) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Você só pode trocar picks do seu time']);
                    exit;
                }
            }

            $stmtToTeam = $pdo->prepare('SELECT id, league FROM teams WHERE id = ?');
            $stmtToTeam->execute([$toTeamId]);
            $toTeam = $stmtToTeam->fetch(PDO::FETCH_ASSOC);
            if (!$toTeam) {
                echo json_encode(['success' => false, 'error' => 'Time de destino não encontrado']);
                exit;
            }

            if (($toTeam['league'] ?? null) !== ($session['league'] ?? null)) {
                echo json_encode(['success' => false, 'error' => 'O time de destino precisa ser da mesma liga']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE draft_order SET team_id = ?, traded_from_team_id = ? WHERE id = ?')
                    ->execute([(int)$toTeamId, (int)$fromTeamId, (int)$pickId]);
                $isCurrentPick = ($session['status'] ?? '') === 'in_progress'
                    && (int)$pick['round'] === (int)$session['current_round']
                    && (int)$pick['pick_position'] === (int)$session['current_pick'];
                if ($isCurrentPick) {
                    $pdo->prepare('UPDATE draft_sessions SET current_pick_started_at = NOW() WHERE id = ?')
                        ->execute([(int)$draftSessionId]);
                }
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Pick trocada com sucesso!']);
                if ($isCurrentPick) {
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    try { notifyNextPick($pdo, (int)$toTeamId, (int)$pick['round'], (int)$pick['pick_position']); } catch (Exception $e) {}
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro ao trocar pick']);
            }
            break;

        // ADMIN: Iniciar draft
        case 'start_draft':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "setup"');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já iniciada']);
                exit;
            }

            $stmtOrder = $pdo->prepare('SELECT COUNT(*) as total FROM draft_order WHERE draft_session_id = ?');
            $stmtOrder->execute([$draftSessionId]);
            $orderCount = (int)($stmtOrder->fetch()['total'] ?? 0);
            if ($orderCount === 0) {
                echo json_encode(['success' => false, 'error' => 'Defina a ordem do draft antes de iniciar']);
                exit;
            }

            $pdo->prepare('UPDATE draft_sessions SET status = "in_progress", started_at = NOW(), current_pick_started_at = NOW() WHERE id = ?')->execute([(int)$draftSessionId]);

            // Busca o primeiro time para notificar após resposta
            $stmtFirst = $pdo->prepare('SELECT team_id, round, pick_position FROM draft_order WHERE draft_session_id = ? AND picked_player_id IS NULL ORDER BY round ASC, pick_position ASC LIMIT 1');
            $stmtFirst->execute([(int)$draftSessionId]);
            $firstPick = $stmtFirst->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'message' => 'Draft iniciado!']);
            if ($firstPick) {
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                try { notifyNextPick($pdo, (int)$firstPick['team_id'], (int)$firstPick['round'], (int)$firstPick['pick_position']); } catch (Exception $e) {}
            }
            break;

        /* ADMIN: AJUSTAR AS PICKS DE UM DRAFT JÁ ABERTO.
           A sincronização roda sozinha em três momentos — ao aplicar a ordem
           da loteria, ao abrir o draft e ao aceitar qualquer troca. Faltava o
           quarto: um draft que JÁ está rolando e ficou com a ordem errada.
           Isso acontece com troca aceita antes de a correção existir, ou com
           a ordem aplicada antes da troca. Sem esta ação, a única saída era
           reaplicar a ordem da loteria — o que reembaralha um draft em
           andamento e é remédio pior que a doença.
           É a mesma conta do "Revisar picks", só que gravando. */
        case 'ajustar_picks': {
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            $sid = (int)($data['draft_session_id'] ?? 0);
            if (!$sid) { echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']); exit; }

            $st = $pdo->prepare('SELECT league FROM draft_sessions WHERE id = ?');
            $st->execute([$sid]);
            $ligaSessao = (string)($st->fetchColumn() ?: '');
            if ($ligaSessao === '') { echo json_encode(['success' => false, 'error' => 'Sessão não encontrada']); exit; }

            $minhas = array_values(array_intersect(['ELITE','NEXT','RISE','ROOKIE'], getAdminLeagues($pdo, (int)$user['id'])));
            if (!in_array($ligaSessao, $minhas, true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Você não administra a liga deste draft']);
                exit;
            }

            $r = draftSincronizarOrdem($pdo, $sid);
            $mexeu = (int)$r['donos'] + (int)$r['swaps'];
            $msg = $mexeu === 0
                ? 'Nada a ajustar: cada escolha já está com o dono certo.'
                : trim(($r['donos'] ? $r['donos'] . ' escolha(s) ajustada(s) por troca de pick. ' : '')
                     . ($r['swaps'] ? $r['swaps'] . ' escolha(s) trocada(s) por swap.' : ''));
            echo json_encode(['success' => true, 'ajustadas' => $mexeu, 'message' => $msg]);
            break;
        }

        // ADMIN: Finalizar draft manualmente
        case 'finalize_draft':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ?');
            $stmtSession->execute([(int)$draftSessionId]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada']);
                exit;
            }

            $pdo->prepare('UPDATE draft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
                ->execute([(int)$draftSessionId]);

            echo json_encode(['success' => true, 'message' => 'Draft finalizado!']);
            break;

        // ADMIN: Adicionar jogador ao draft pool
        case 'add_draft_player':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            $name = trim((string)($data['name'] ?? ''));
            $position = strtoupper(trim((string)($data['position'] ?? '')));
            $age = (int)($data['age'] ?? 0);
            $ovr = (int)($data['ovr'] ?? 0);

            if (!$draftSessionId || $name === '' || $position === '' || $age <= 0 || $ovr <= 0) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ?');
            $stmtSession->execute([(int)$draftSessionId]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada']);
                exit;
            }

            $stmt = $pdo->prepare('INSERT INTO draft_pool (season_id, name, position, age, ovr, draft_status) VALUES (?, ?, ?, ?, ?, "available")');
            $stmt->execute([
                (int)$session['season_id'],
                $name,
                $position,
                $age,
                $ovr
            ]);

            echo json_encode(['success' => true, 'message' => 'Jogador adicionado ao draft!']);
            break;

        // ADMIN: Importar jogadores em lote via CSV
        case 'import_draft_players':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            $players = $data['players'] ?? [];

            if (!$draftSessionId || !is_array($players) || empty($players)) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ?');
            $stmtSession->execute([(int)$draftSessionId]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Sessão não encontrada']);
                exit;
            }

            $seasonId = (int)$session['season_id'];
            $inserted = 0;
            $errors = [];
            $stmtInsert = $pdo->prepare('INSERT INTO draft_pool (season_id, name, position, age, ovr, pick_hint, draft_status) VALUES (?, ?, ?, ?, ?, ?, "available")');

            foreach ($players as $i => $p) {
                $pName     = trim((string)($p['name'] ?? ''));
                $pPosition = strtoupper(trim((string)($p['position'] ?? '')));
                $pAge      = (int)($p['age'] ?? 0);
                $pOvr      = (int)($p['ovr'] ?? 0);
                $pHintRaw  = $p['pick_hint'] ?? null;
                $pHint     = ($pHintRaw !== null && $pHintRaw !== '') ? (int)$pHintRaw : null;

                if ($pName === '' || $pPosition === '' || $pAge <= 0 || $pOvr <= 0) {
                    $errors[] = 'Linha ' . ($i + 2) . ': dados inválidos (nome=' . $pName . ', pos=' . $pPosition . ', age=' . $pAge . ', ovr=' . $pOvr . ')';
                    continue;
                }

                try {
                    $stmtInsert->execute([$seasonId, $pName, $pPosition, $pAge, $pOvr, $pHint]);
                    $inserted++;
                } catch (Exception $ex) {
                    $errors[] = 'Linha ' . ($i + 2) . ': ' . $ex->getMessage();
                }
            }

            echo json_encode([
                'success' => true,
                'inserted' => $inserted,
                'errors' => $errors,
                'message' => "$inserted jogador(es) importado(s)" . (count($errors) ? ' com ' . count($errors) . ' erro(s).' : '.'),
            ]);
            break;

        // JOGADOR/ADMIN: Fazer pick
        case 'make_pick':
            $draftSessionId = $data['draft_session_id'] ?? null;
            $playerId = $data['player_id'] ?? null;
            $teamIdOverride = $data['team_id'] ?? null; // Admin pode definir outro time
            $roundOverride = $isAdmin ? ($data['round'] ?? null) : null;
            $pickIdOverride = $isAdmin ? ($data['pick_id'] ?? null) : null;
            if (!$draftSessionId || !$playerId) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "in_progress"');
            $stmtSession->execute([$draftSessionId]);
            $session = $stmtSession->fetch();
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Draft não está em andamento']);
                exit;
            }

            $stmtSeasonNum = $pdo->prepare('SELECT season_number FROM seasons WHERE id = ?');
            $stmtSeasonNum->execute([$session['season_id']]);
            $draftSeasonNumber = (int)($stmtSeasonNum->fetchColumn() ?: 1);

            $currentPick = null;

            if ($isAdmin && $pickIdOverride) {
                $stmtPick = $pdo->prepare('SELECT * FROM draft_order WHERE id = ? AND draft_session_id = ? AND picked_player_id IS NULL');
                $stmtPick->execute([(int)$pickIdOverride, (int)$draftSessionId]);
                $currentPick = $stmtPick->fetch();
            }

            if (!$currentPick && $isAdmin && $roundOverride) {
                $roundOverride = (int)$roundOverride;

                if ($teamIdOverride) {
                    $stmtPick = $pdo->prepare(
                        'SELECT * FROM draft_order 
                         WHERE draft_session_id = ? AND round = ? AND team_id = ? AND picked_player_id IS NULL
                         ORDER BY pick_position ASC LIMIT 1'
                    );
                    $stmtPick->execute([(int)$draftSessionId, $roundOverride, (int)$teamIdOverride]);
                    $currentPick = $stmtPick->fetch();
                }

                if (!$currentPick) {
                    $stmtPick = $pdo->prepare(
                        'SELECT * FROM draft_order 
                         WHERE draft_session_id = ? AND round = ? AND picked_player_id IS NULL
                         ORDER BY pick_position ASC LIMIT 1'
                    );
                    $stmtPick->execute([(int)$draftSessionId, $roundOverride]);
                    $currentPick = $stmtPick->fetch();
                }
            }

            if (!$currentPick) {
                $stmtPick = $pdo->prepare('SELECT * FROM draft_order WHERE draft_session_id = ? AND round = ? AND pick_position = ? AND picked_player_id IS NULL');
                $stmtPick->execute([(int)$draftSessionId, (int)$session['current_round'], (int)$session['current_pick']]);
                $currentPick = $stmtPick->fetch();
            }

            if (!$currentPick) {
                echo json_encode(['success' => false, 'error' => 'Nenhuma pick pendente para a rodada informada']);
                exit;
            }

            $targetTeamId = $isAdmin && $teamIdOverride ? (int)$teamIdOverride : (int)$currentPick['team_id'];
            if (!$isAdmin && (int)$currentPick['team_id'] !== (int)$team['id']) {
                echo json_encode(['success' => false, 'error' => 'Não é a sua vez de escolher']);
                exit;
            }

            $stmtPlayer = $pdo->prepare('SELECT * FROM draft_pool WHERE id = ? AND draft_status = "available"');
            $stmtPlayer->execute([(int)$playerId]);
            $player = $stmtPlayer->fetch();
            if (!$player) {
                echo json_encode(['success' => false, 'error' => 'Jogador não disponível']);
                exit;
            }

            try {
                $duplicateRoster = false;
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE draft_order SET picked_player_id = ?, picked_at = NOW(), team_id = ? WHERE id = ?')
                    ->execute([(int)$playerId, (int)$targetTeamId, (int)$currentPick['id']]);

                $stmtTotalRound = $pdo->prepare('SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = ?');
                $stmtTotalRound->execute([(int)$draftSessionId, (int)$currentPick['round']]);
                $roundSize = (int)$stmtTotalRound->fetchColumn();
                $pickNumber = (($currentPick['round'] - 1) * $roundSize) + $currentPick['pick_position'];

                $pdo->prepare('UPDATE draft_pool SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ? WHERE id = ?')
                    ->execute([(int)$targetTeamId, (int)$pickNumber, (int)$playerId]);

                $playerName = trim((string)($player['name'] ?? ''));
                $stmtExisting = $pdo->prepare('SELECT id FROM players WHERE team_id = ? AND name = ? LIMIT 1');
                $stmtExisting->execute([(int)$targetTeamId, $playerName]);
                $existingPlayerId = $stmtExisting->fetchColumn();

                if ($existingPlayerId) {
                    $duplicateRoster = true;
                } else {
                    try {
                        $pdo->prepare('INSERT INTO players (team_id, drafted_by_team_id, drafted_season_number, draft_round, draft_pick_position, name, position, age, ovr, role, available_for_trade) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "Banco", 0)')
                            ->execute([(int)$targetTeamId, (int)$targetTeamId, $draftSeasonNumber, (int)$currentPick['round'], (int)$currentPick['pick_position'], $playerName, $player['position'], (int)$player['age'], (int)$player['ovr']]);
                    } catch (Exception $e) {
                        if (str_contains($e->getMessage(), 'unique_player_per_team')) {
                            $duplicateRoster = true;
                        } else {
                            throw $e;
                        }
                    }
                }

                $stmtNext = $pdo->prepare('SELECT round, pick_position FROM draft_order WHERE draft_session_id = ? AND picked_player_id IS NULL ORDER BY round ASC, pick_position ASC LIMIT 1');
                $stmtNext->execute([(int)$draftSessionId]);
                $next = $stmtNext->fetch(PDO::FETCH_ASSOC);

                $nextTeamId = null;
                $nextRound = null;
                $nextPick = null;
                if ($next) {
                    $pdo->prepare('UPDATE draft_sessions SET current_round = ?, current_pick = ?, current_pick_started_at = NOW() WHERE id = ?')
                        ->execute([(int)$next['round'], (int)$next['pick_position'], (int)$draftSessionId]);
                    // Pega o team_id da próxima pick para notificar
                    $stmtNextTeam = $pdo->prepare('SELECT team_id FROM draft_order WHERE draft_session_id = ? AND round = ? AND pick_position = ? LIMIT 1');
                    $stmtNextTeam->execute([(int)$draftSessionId, (int)$next['round'], (int)$next['pick_position']]);
                    $nextTeamId = (int)($stmtNextTeam->fetchColumn() ?: 0);
                } else {
                    $pdo->prepare('UPDATE draft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')->execute([(int)$draftSessionId]);
                }

                $pdo->commit();

                $message = $duplicateRoster
                    ? 'Pick realizada! Jogador já existia no elenco e não foi duplicado.'
                    : 'Pick realizada!';
                echo json_encode(['success' => true, 'message' => $message, 'player' => $player]);
                if ($nextTeamId && isset($next)) {
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    try { notifyNextPick($pdo, $nextTeamId, (int)$next['round'], (int)$next['pick_position']); } catch (Exception $e) {}
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro ao fazer pick']);
            }
            break;

        // 2ª rodada: dono da pick (ou admin, pra qualquer pick) deixa/troca o mock dela —
        // upsert, só pode setar em pick ainda não resolvida.
        case 'submit_round2_mock':
            $draftOrderId = $data['draft_order_id'] ?? null;
            $playerId = $data['player_id'] ?? null;
            if (!$draftOrderId || !$playerId) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }
            $stmtPick = $pdo->prepare('SELECT * FROM draft_order WHERE id = ? AND round = 2 AND picked_player_id IS NULL');
            $stmtPick->execute([(int)$draftOrderId]);
            $pick = $stmtPick->fetch(PDO::FETCH_ASSOC);
            if (!$pick) {
                echo json_encode(['success' => false, 'error' => 'Pick não encontrada ou já resolvida']);
                exit;
            }
            if (!$isAdmin && (!$team || (int)$pick['team_id'] !== (int)$team['id'])) {
                echo json_encode(['success' => false, 'error' => 'Essa pick não é sua']);
                exit;
            }
            $stmtPlayer = $pdo->prepare('SELECT id FROM draft_pool WHERE id = ? AND draft_status = "available"');
            $stmtPlayer->execute([(int)$playerId]);
            if (!$stmtPlayer->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Jogador não disponível']);
                exit;
            }
            $pdo->prepare(
                'INSERT INTO draft_round2_mocks (draft_order_id, team_id, player_id) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE player_id = VALUES(player_id), created_at = NOW()'
            )->execute([(int)$draftOrderId, (int)$pick['team_id'], (int)$playerId]);
            echo json_encode(['success' => true, 'message' => 'Mock salvo!']);
            break;

        // 2ª rodada: dono da pick (ou admin) remove o mock dela
        case 'cancel_round2_mock':
            $draftOrderId = $data['draft_order_id'] ?? null;
            if (!$draftOrderId) {
                echo json_encode(['success' => false, 'error' => 'draft_order_id obrigatório']);
                exit;
            }
            $stmtPick = $pdo->prepare('SELECT team_id FROM draft_order WHERE id = ? AND round = 2');
            $stmtPick->execute([(int)$draftOrderId]);
            $ownerTeamId = $stmtPick->fetchColumn();
            if (!$ownerTeamId) {
                echo json_encode(['success' => false, 'error' => 'Pick não encontrada']);
                exit;
            }
            if (!$isAdmin && (!$team || (int)$ownerTeamId !== (int)$team['id'])) {
                echo json_encode(['success' => false, 'error' => 'Essa pick não é sua']);
                exit;
            }
            $pdo->prepare('DELETE FROM draft_round2_mocks WHERE draft_order_id = ?')->execute([(int)$draftOrderId]);
            echo json_encode(['success' => true, 'message' => 'Mock removido']);
            break;

        // ADMIN: força a resolução da rodada 2 agora, sem esperar os 20min do relógio
        case 'resolve_round2_now':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }
            resolveRound2MocksIfDue($pdo, (int)$draftSessionId, true);
            echo json_encode(['success' => true, 'message' => 'Rodada 2 resolvida!']);
            break;

// ADMIN: Preencher pick de draft passado/completado
        case 'fill_past_pick':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $pickId = $data['pick_id'] ?? null;
            $playerId = $data['player_id'] ?? null;
            $draftSessionId = $data['draft_session_id'] ?? null;
            if (!$pickId || !$playerId || !$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }

            try {
                $duplicateRoster = false;
                $pdo->beginTransaction();

                $stmtPick = $pdo->prepare('SELECT * FROM draft_order WHERE id = ?');
                $stmtPick->execute([(int)$pickId]);
                $pick = $stmtPick->fetch();
                if (!$pick) {
                    throw new Exception('Pick não encontrada');
                }

                $stmtPlayer = $pdo->prepare('SELECT * FROM draft_pool WHERE id = ? AND draft_status = "available"');
                $stmtPlayer->execute([(int)$playerId]);
                $player = $stmtPlayer->fetch();
                if (!$player) {
                    throw new Exception('Jogador não disponível no draft pool');
                }

                $stmtSession = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ?');
                $stmtSession->execute([(int)$draftSessionId]);
                $session = $stmtSession->fetch();
                if (!$session) {
                    throw new Exception('Sessão de draft não encontrada');
                }

                $stmtSeasonNum2 = $pdo->prepare('SELECT season_number FROM seasons WHERE id = ?');
                $stmtSeasonNum2->execute([$session['season_id']]);
                $batchDraftSeasonNumber = (int)($stmtSeasonNum2->fetchColumn() ?: 1);

                $pdo->prepare('UPDATE draft_order SET picked_player_id = ?, picked_at = NOW() WHERE id = ?')->execute([(int)$playerId, (int)$pickId]);

                $stmtTotalRound = $pdo->prepare('SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = ?');
                $stmtTotalRound->execute([(int)$draftSessionId, (int)$pick['round']]);
                $roundSize = (int)$stmtTotalRound->fetchColumn();
                $pickNumber = (($pick['round'] - 1) * $roundSize) + $pick['pick_position'];

                $pdo->prepare('UPDATE draft_pool SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ? WHERE id = ?')
                    ->execute([(int)$pick['team_id'], (int)$pickNumber, (int)$playerId]);

                $playerName = trim((string)($player['name'] ?? ''));
                try {
                    $pdo->prepare('INSERT INTO players (team_id, drafted_by_team_id, drafted_season_number, draft_round, draft_pick_position, name, position, age, ovr, role, available_for_trade) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "Banco", 0)')
                        ->execute([(int)$pick['team_id'], (int)$pick['team_id'], $batchDraftSeasonNumber, (int)$pick['round'], (int)$pick['pick_position'], $playerName, $player['position'], (int)$player['age'], (int)$player['ovr']]);
                } catch (Exception $e) {
                    if (str_contains($e->getMessage(), 'unique_player_per_team')) {
                        $duplicateRoster = true;
                    } else {
                        throw $e;
                    }
                }

                $nextTeamId = null;
                if (($session['status'] ?? '') === 'in_progress'
                    && (int)$pick['round'] === (int)$session['current_round']
                    && (int)$pick['pick_position'] === (int)$session['current_pick']) {

                    $nextPick = (int)$session['current_pick'] + 1;
                    $nextRound = (int)$session['current_round'];

                    $stmtCount = $pdo->prepare('SELECT COUNT(*) as total FROM draft_order WHERE draft_session_id = ? AND round = ?');
                    $stmtCount->execute([(int)$draftSessionId, (int)$nextRound]);
                    $totalPicks = (int)($stmtCount->fetch()['total'] ?? 0);

                    if ($nextPick > $totalPicks) {
                        $nextRound++;
                        $nextPick = 1;
                        if ($nextRound > (int)$session['total_rounds']) {
                            $pdo->prepare('UPDATE draft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')->execute([(int)$draftSessionId]);
                        } else {
                            $pdo->prepare('UPDATE draft_sessions SET current_round = ?, current_pick = ?, current_pick_started_at = NOW() WHERE id = ?')
                                ->execute([(int)$nextRound, (int)$nextPick, (int)$draftSessionId]);
                            $stmtNextTeam = $pdo->prepare('SELECT team_id FROM draft_order WHERE draft_session_id = ? AND round = ? AND pick_position = ? LIMIT 1');
                            $stmtNextTeam->execute([(int)$draftSessionId, (int)$nextRound, (int)$nextPick]);
                            $nextTeamId = (int)($stmtNextTeam->fetchColumn() ?: 0);
                        }
                    } else {
                        $pdo->prepare('UPDATE draft_sessions SET current_pick = ?, current_pick_started_at = NOW() WHERE id = ?')
                            ->execute([(int)$nextPick, (int)$draftSessionId]);
                        $stmtNextTeam = $pdo->prepare('SELECT team_id FROM draft_order WHERE draft_session_id = ? AND round = ? AND pick_position = ? LIMIT 1');
                        $stmtNextTeam->execute([(int)$draftSessionId, (int)$nextRound, (int)$nextPick]);
                        $nextTeamId = (int)($stmtNextTeam->fetchColumn() ?: 0);
                    }
                }

                $pdo->commit();
                $message = $duplicateRoster
                    ? 'Pick preenchida! Jogador já existia no elenco e não foi duplicado.'
                    : 'Pick preenchida!';
                echo json_encode(['success' => true, 'message' => $message, 'player' => $player]);
                if ($nextTeamId) {
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    try { notifyNextPick($pdo, $nextTeamId, (int)$nextRound, (int)$nextPick); } catch (Exception $e) {}
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']);
            }
            break;

        // ADMIN: Resetar draft
        case 'reset_draft':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $draftSessionId = $data['draft_session_id'] ?? null;
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE draft_order SET picked_player_id = NULL, picked_at = NULL WHERE draft_session_id = ?')->execute([(int)$draftSessionId]);
                $pdo->prepare('UPDATE draft_sessions SET status = "setup", current_round = 1, current_pick = 1, started_at = NULL WHERE id = ?')->execute([(int)$draftSessionId]);

                $stmtSession = $pdo->prepare('SELECT season_id FROM draft_sessions WHERE id = ?');
                $stmtSession->execute([(int)$draftSessionId]);
                $session = $stmtSession->fetch();
                $pdo->prepare('UPDATE draft_pool SET draft_status = "available", drafted_by_team_id = NULL, draft_order = NULL WHERE season_id = ?')->execute([(int)$session['season_id']]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Draft resetado']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro ao resetar']);
            }
            break;

        case 'revert_pick':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            $pickId = (int)($data['pick_id'] ?? 0);
            if (!$pickId) {
                echo json_encode(['success' => false, 'error' => 'pick_id obrigatório']);
                exit;
            }
            try {
                // Busca a pick e o jogador draftado
                $stmtPick = $pdo->prepare('SELECT do_.*, dp.name AS pool_name, dp.position AS pool_pos FROM draft_order do_ JOIN draft_pool dp ON dp.id = do_.picked_player_id WHERE do_.id = ? AND do_.picked_player_id IS NOT NULL LIMIT 1');
                $stmtPick->execute([$pickId]);
                $pick = $stmtPick->fetch(PDO::FETCH_ASSOC);
                if (!$pick) {
                    echo json_encode(['success' => false, 'error' => 'Pick não encontrada ou ainda não foi realizada']);
                    exit;
                }

                // Temporada do draft (junto com round/pick_position identifica a linha certa em
                // players — o mesmo time pode ter feito a pick #X da rodada Y em anos diferentes).
                $stmtSeasonNum = $pdo->prepare('SELECT s.season_number FROM draft_sessions ds JOIN seasons s ON s.id = ds.season_id WHERE ds.id = ?');
                $stmtSeasonNum->execute([(int)$pick['draft_session_id']]);
                $revertSeasonNumber = $stmtSeasonNum->fetchColumn();
                $revertSeasonNumber = ($revertSeasonNumber === false) ? null : $revertSeasonNumber;

                $pdo->beginTransaction();
                // Remove o jogador do elenco (apenas se foi inserido por este draft).
                // Casa por identificadores estáveis da própria pick (time + rodada + posição +
                // temporada), não por nome: se o nome do jogador foi editado depois do draft, um
                // match por nome não acha a linha, o DELETE não remove ninguém, o slot volta a
                // "disponível" no pool e o jogador some do time — abrindo brecha pra draft
                // duplicado do mesmo jogador. draft_round/draft_pick_position/drafted_season_number
                // são gravados em players no momento do pick (make_pick/fill_past_pick/check_autopick).
                $stmtDeletePlayer = $pdo->prepare('
                    DELETE FROM players
                    WHERE team_id = ? AND drafted_by_team_id = ?
                      AND draft_round = ? AND draft_pick_position = ?
                      AND (drafted_season_number = ? OR ? IS NULL)
                    LIMIT 1
                ');
                $stmtDeletePlayer->execute([
                    (int)$pick['team_id'], (int)$pick['team_id'],
                    (int)$pick['round'], (int)$pick['pick_position'],
                    $revertSeasonNumber, $revertSeasonNumber,
                ]);

                // Fallback pra elencos legados sem draft_round/draft_pick_position preenchidos
                // (players inseridos antes dessas colunas existirem): casa pelo nome, como antes.
                if ($stmtDeletePlayer->rowCount() === 0) {
                    $pdo->prepare('DELETE FROM players WHERE team_id = ? AND drafted_by_team_id = ? AND name = ? LIMIT 1')
                        ->execute([(int)$pick['team_id'], (int)$pick['team_id'], $pick['pool_name']]);
                }
                // Devolve ao pool
                $pdo->prepare('UPDATE draft_pool SET draft_status = "available", drafted_by_team_id = NULL, draft_order = NULL WHERE id = ?')
                    ->execute([(int)$pick['picked_player_id']]);
                // Limpa a pick
                $pdo->prepare('UPDATE draft_order SET picked_player_id = NULL, picked_at = NULL WHERE id = ?')
                    ->execute([$pickId]);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => "{$pick['pool_name']} devolvido ao pool."]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro']);
            }
            break;

        case 'set_current_pick':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            $draftSessionId = (int)($data['draft_session_id'] ?? 0);
            $round          = (int)($data['round'] ?? 1);
            $pickPosition   = (int)($data['pick_position'] ?? 1);
            if (!$draftSessionId || !$round || !$pickPosition) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }
            try {
                // Checagens explicitas: rowCount() nao serve aqui porque o MySQL
                // devolve 0 tambem quando a escolha ja e a atual (valor inalterado).
                $stmtChk = $pdo->prepare('SELECT status FROM draft_sessions WHERE id = ?');
                $stmtChk->execute([$draftSessionId]);
                $status = $stmtChk->fetchColumn();
                if ($status === false) {
                    echo json_encode(['success' => false, 'error' => 'Draft não encontrado']);
                    exit;
                }
                if ($status !== 'in_progress') {
                    echo json_encode(['success' => false, 'error' => 'O draft precisa estar em andamento para definir a escolha atual']);
                    exit;
                }

                $stmtSlot = $pdo->prepare('SELECT picked_player_id FROM draft_order WHERE draft_session_id = ? AND round = ? AND pick_position = ?');
                $stmtSlot->execute([$draftSessionId, $round, $pickPosition]);
                $slot = $stmtSlot->fetch(PDO::FETCH_ASSOC);
                if (!$slot) {
                    echo json_encode(['success' => false, 'error' => "Não existe escolha #{$pickPosition} na rodada {$round}"]);
                    exit;
                }
                if ($slot['picked_player_id'] !== null) {
                    echo json_encode(['success' => false, 'error' => "A escolha #{$pickPosition} já foi feita; reverta ela antes de voltar o draft para lá"]);
                    exit;
                }

                $pdo->prepare('UPDATE draft_sessions SET current_round = ?, current_pick = ?, current_pick_started_at = NOW() WHERE id = ?')
                    ->execute([$round, $pickPosition, $draftSessionId]);
                echo json_encode(['success' => true, 'message' => "Escolha atual definida: Rodada {$round} · #{$pickPosition}"]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Erro']);
            }
            break;

        // ADMIN: agenda (ou limpa, se vier vazio) o relógio da 1ª rodada — a partir dessa
        // data/hora, a pick atual passa a ter 5min (fallback pela ordem geral se a fila
        // pessoal do time não resolver), em vez do prazo de 30min de sempre.
        case 'set_round1_clock':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            $draftSessionId = (int)($data['draft_session_id'] ?? 0);
            if (!$draftSessionId) {
                echo json_encode(['success' => false, 'error' => 'draft_session_id obrigatório']);
                exit;
            }
            $rawValue = trim((string)($data['round1_clock_start_at'] ?? ''));
            $clockValue = null;
            if ($rawValue !== '') {
                $ts = strtotime($rawValue);
                if ($ts === false) {
                    echo json_encode(['success' => false, 'error' => 'Data/hora inválida']);
                    exit;
                }
                $clockValue = date('Y-m-d H:i:s', $ts);
            }
            $pdo->prepare('UPDATE draft_sessions SET round1_clock_start_at = ? WHERE id = ?')
                ->execute([$clockValue, $draftSessionId]);
            echo json_encode([
                'success' => true,
                'message' => $clockValue ? "Relógio da 1ª rodada agendado para {$clockValue}" : 'Relógio da 1ª rodada removido',
                'round1_clock_start_at' => $clockValue,
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);

?>
