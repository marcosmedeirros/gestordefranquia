<?php
/**
 * Estatísticas por temporada e snapshot do elenco.
 *
 * GET  ?action=season_stats&player_id=  → série por temporada de um jogador
 * POST action=save_stats                → grava/atualiza as estatísticas da temporada
 * POST action=save_snapshot             → congela OVR, idade e letras da temporada
 *
 * O GM só mexe no próprio elenco; admin pode gravar em qualquer time da liga
 * que administra.
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

$stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$meuTime = $stmtTeam->fetch(PDO::FETCH_ASSOC);

/** Temporada ativa da liga (a que ainda não terminou). */
function temporadaAtiva(PDO $pdo, string $league): ?array {
    $st = $pdo->prepare("SELECT id, season_number, year FROM seasons
                         WHERE league = ? AND (status IS NULL OR status <> 'completed')
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$league]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── GET: série do jogador ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'season_stats') {
    $playerId = (int)($_GET['player_id'] ?? 0);
    if (!$playerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'player_id obrigatório']);
        exit;
    }

    $st = $pdo->prepare("
        SELECT s.season_number, s.year, ps.games, ps.min_pg, ps.pts_pg, ps.reb_pg,
               ps.ast_pg, ps.stl_pg, ps.blk_pg, ps.source, ps.updated_at,
               CONCAT(t.city,' ',t.name) AS team_name
        FROM player_season_stats ps
        LEFT JOIN seasons s ON s.id = ps.season_id
        LEFT JOIN teams   t ON t.id = ps.team_id
        WHERE ps.player_id = ?
        ORDER BY s.season_number ASC, ps.id ASC
    ");
    $st->execute([$playerId]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);

    // Totais: a média das médias precisa ser ponderada pelos jogos, senão uma
    // temporada de 3 jogos pesa o mesmo que uma de 82.
    $jogos = 0;
    $soma = ['min_pg' => 0, 'pts_pg' => 0, 'reb_pg' => 0, 'ast_pg' => 0, 'stl_pg' => 0, 'blk_pg' => 0];
    foreach ($linhas as $l) {
        $g = (int)$l['games'];
        if ($g <= 0) continue;
        $jogos += $g;
        foreach ($soma as $k => $_) $soma[$k] += (float)$l[$k] * $g;
    }
    $carreira = ['games' => $jogos, 'temporadas' => count($linhas)];
    foreach ($soma as $k => $v) $carreira[$k] = $jogos > 0 ? round($v / $jogos, 1) : 0;

    echo json_encode(['success' => true, 'seasons' => $linhas, 'career' => $carreira]);
    exit;
}

// Elenco atual de um time com as estatísticas da temporada corrente.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'team_roster_stats') {
    $tid = (int)($_GET['team_id'] ?? 0);
    if (!$tid) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'team_id obrigatório']);
        exit;
    }
    $stT = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $stT->execute([$tid]);
    $lg = $stT->fetchColumn();
    if (!$lg) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
        exit;
    }
    $temp = temporadaAtiva($pdo, (string)$lg);

    $st = $pdo->prepare("
        SELECT p.id, p.name, p.position, p.secondary_position, p.ovr, p.age, p.role,
               ps.games, ps.min_pg, ps.pts_pg, ps.reb_pg, ps.ast_pg, ps.stl_pg, ps.blk_pg
        FROM players p
        LEFT JOIN player_season_stats ps
               ON ps.player_id = p.id AND ps.season_id <=> ?
        WHERE p.team_id = ?
        ORDER BY (ps.pts_pg IS NULL), ps.pts_pg DESC, p.ovr DESC
    ");
    $st->execute([$temp['id'] ?? null, $tid]);

    echo json_encode([
        'success'       => true,
        'season_number' => $temp['season_number'] ?? null,
        'players'       => $st->fetchAll(PDO::FETCH_ASSOC),
    ]);
    exit;
}

// ── POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if (!$meuTime) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Você não tem time nesta liga.']);
    exit;
}
$teamId  = (int)$meuTime['id'];
$league  = (string)$meuTime['league'];
$season  = temporadaAtiva($pdo, $league);
if (!$season) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nenhuma temporada em andamento nesta liga.']);
    exit;
}

/** Ids do elenco — nada fora dele pode ser gravado. */
$stmtEl = $pdo->prepare('SELECT id FROM players WHERE team_id = ?');
$stmtEl->execute([$teamId]);
$doElenco = array_map('intval', $stmtEl->fetchAll(PDO::FETCH_COLUMN));

if ($action === 'save_stats') {
    $itens = $body['stats'] ?? [];
    if (!is_array($itens) || !$itens) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nada para salvar.']);
        exit;
    }

    $sql = "INSERT INTO player_season_stats
              (player_id, season_id, season_number, league, team_id,
               games, min_pg, pts_pg, reb_pg, ast_pg, stl_pg, blk_pg, source)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              games=VALUES(games), min_pg=VALUES(min_pg), pts_pg=VALUES(pts_pg),
              reb_pg=VALUES(reb_pg), ast_pg=VALUES(ast_pg), stl_pg=VALUES(stl_pg),
              blk_pg=VALUES(blk_pg), source=VALUES(source), team_id=VALUES(team_id)";
    $stmt = $pdo->prepare($sql);

    $ok = 0; $ignorados = 0;
    $num = fn($v, $max) => max(0, min($max, round((float)($v ?? 0), 1)));

    $pdo->beginTransaction();
    try {
        foreach ($itens as $it) {
            $pid = (int)($it['player_id'] ?? 0);
            if (!$pid || !in_array($pid, $doElenco, true)) { $ignorados++; continue; }
            $stmt->execute([
                $pid, (int)$season['id'], (int)$season['season_number'], $league, $teamId,
                max(0, min(200, (int)($it['games'] ?? 0))),
                $num($it['min_pg'] ?? 0, 60),
                $num($it['pts_pg'] ?? 0, 99),
                $num($it['reb_pg'] ?? 0, 50),
                $num($it['ast_pg'] ?? 0, 50),
                $num($it['stl_pg'] ?? 0, 20),
                $num($it['blk_pg'] ?? 0, 20),
                ($it['source'] ?? 'manual') === 'foto' ? 'foto' : 'manual',
            ]);
            $ok++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao salvar as estatísticas.']);
        exit;
    }

    echo json_encode(['success' => true, 'saved' => $ok, 'skipped' => $ignorados,
                      'season_number' => (int)$season['season_number']]);
    exit;
}

if ($action === 'save_snapshot') {
    // Congela o estado atual do elenco na temporada. Rodar de novo na mesma
    // temporada atualiza a linha em vez de duplicar.
    $stmtP = $pdo->prepare("SELECT id, name, ovr, age, position,
                                   skill_in, skill_mid, skill_3pt, skill_post_d, skill_per_d,
                                   skill_play, skill_reb, skill_athl, skill_iq, skill_pot
                            FROM players WHERE team_id = ?");
    $stmtP->execute([$teamId]);
    $jogadores = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    if (!$jogadores) {
        echo json_encode(['success' => true, 'saved' => 0]);
        exit;
    }

    $stmtTime = $pdo->prepare("SELECT CONCAT(city,' ',name) AS nome FROM teams WHERE id = ?");
    $stmtTime->execute([$teamId]);
    $nomeTime = $stmtTime->fetchColumn() ?: '';

    $stmtSel = $pdo->prepare('SELECT id FROM player_season_log WHERE player_id = ? AND season_id = ? LIMIT 1');
    $campos = 'ovr=?, age=?, position=?, team_id=?, team_name=?, league=?, season_number=?, year=?,
               skill_in=?, skill_mid=?, skill_3pt=?, skill_post_d=?, skill_per_d=?,
               skill_play=?, skill_reb=?, skill_athl=?, skill_iq=?, skill_pot=?';
    $stmtUpd = $pdo->prepare("UPDATE player_season_log SET {$campos} WHERE id = ?");
    $stmtIns = $pdo->prepare("INSERT INTO player_season_log
        (player_id, player_name, season_id, ovr, age, position, team_id, team_name, league,
         season_number, year, skill_in, skill_mid, skill_3pt, skill_post_d, skill_per_d,
         skill_play, skill_reb, skill_athl, skill_iq, skill_pot)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $ok = 0;
    $pdo->beginTransaction();
    try {
        foreach ($jogadores as $p) {
            $skills = [$p['skill_in'], $p['skill_mid'], $p['skill_3pt'], $p['skill_post_d'],
                       $p['skill_per_d'], $p['skill_play'], $p['skill_reb'], $p['skill_athl'],
                       $p['skill_iq'], $p['skill_pot']];
            $stmtSel->execute([(int)$p['id'], (int)$season['id']]);
            $existente = $stmtSel->fetchColumn();

            $comuns = [(int)$p['ovr'], (int)$p['age'], $p['position'], $teamId, $nomeTime, $league,
                       (int)$season['season_number'], (int)($season['year'] ?? 0)];

            if ($existente) {
                $stmtUpd->execute(array_merge($comuns, $skills, [(int)$existente]));
            } else {
                $stmtIns->execute(array_merge(
                    [(int)$p['id'], $p['name'], (int)$season['id']],
                    $comuns, $skills
                ));
            }
            $ok++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao salvar o histórico da temporada.']);
        exit;
    }

    echo json_encode(['success' => true, 'saved' => $ok,
                      'season_number' => (int)$season['season_number']]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação inválida']);
