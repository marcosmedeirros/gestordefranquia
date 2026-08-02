<?php
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php'; // congelarRankingDaSprint()
require_once dirname(__DIR__) . '/backend/checklist_temporada.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$config = loadConfig();
$runPicksToken = (string)($config['app']['run_picks_token'] ?? (getenv('FBA_RUN_PICKS_TOKEN') ?: ''));
$providedRunPicksToken = (string)($_GET['token'] ?? '');
$hasValidRunPicksToken = $action === 'run_picks'
    && $runPicksToken !== ''
    && $providedRunPicksToken !== ''
    && hash_equals($runPicksToken, $providedRunPicksToken);

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

$user = getUserSession();
if (!$user && !$hasValidRunPicksToken) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if (!$user && $hasValidRunPicksToken) {
    $user = ['id' => 0, 'user_type' => 'admin', 'name' => 'run_picks_token'];
}

// Libera o lock da sessão imediatamente após ler os dados do usuário.
// Isso evita bloqueios quando múltiplas requisições são feitas em paralelo
// (ex.: salvar histórico e, em seguida, carregar o histórico na aba).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function resolveSeasonYear(PDO $pdo, string $league): int {
    $currentYear = (int)date('Y');

    try {
        $hasSeasons = $pdo->query("SHOW TABLES LIKE 'seasons'")->fetch();
        if (!$hasSeasons) {
            return $currentYear;
        }

        $stmtIndexes = $pdo->query('SHOW INDEX FROM seasons');
        $hasYearUnique = false;
        $hasYearLeagueUnique = false;

        if ($stmtIndexes) {
            $uniqueIndexes = [];
            while ($index = $stmtIndexes->fetch(PDO::FETCH_ASSOC)) {
                if ((int)($index['Non_unique'] ?? 1) === 0) {
                    $keyName = $index['Key_name'] ?? '';
                    if (!isset($uniqueIndexes[$keyName])) {
                        $uniqueIndexes[$keyName] = [];
                    }
                    $uniqueIndexes[$keyName][] = $index['Column_name'] ?? '';
                }
            }

            foreach ($uniqueIndexes as $columns) {
                sort($columns);
                if (count($columns) === 1 && $columns[0] === 'year') {
                    $hasYearUnique = true;
                }
                if (count($columns) === 2 && in_array('year', $columns, true) && in_array('league', $columns, true)) {
                    $hasYearLeagueUnique = true;
                }
            }
        }

        $yearCandidate = $currentYear;

        if ($hasYearUnique) {
            $stmtMaxYear = $pdo->query('SELECT COALESCE(MAX(year), 0) as max_year FROM seasons');
            $maxYear = (int)($stmtMaxYear->fetch()['max_year'] ?? 0);
            $yearCandidate = max($yearCandidate, $maxYear + 1);
        }

        if ($hasYearLeagueUnique) {
            $stmtMaxLeagueYear = $pdo->prepare('SELECT COALESCE(MAX(year), 0) as max_year FROM seasons WHERE league = ?');
            $stmtMaxLeagueYear->execute([$league]);
            $maxLeagueYear = (int)($stmtMaxLeagueYear->fetch()['max_year'] ?? 0);
            $yearCandidate = max($yearCandidate, $maxLeagueYear + 1);
        }

        // Só empurra o ano pra frente se o banco de fato exigir ano único. Sem
        // o índice, sprints diferentes podem repetir o ano de propósito (um
        // ciclo novo recomeçando o calendário da liga).
        if ($hasYearUnique || $hasYearLeagueUnique) {
            $stmtExists = $pdo->prepare('SELECT COUNT(*) FROM seasons WHERE league = ? AND year = ?');
            while (true) {
                $stmtExists->execute([$league, $yearCandidate]);
                if ((int)$stmtExists->fetchColumn() === 0) {
                    break;
                }
                $yearCandidate++;
            }
        }

        return $yearCandidate;
    } catch (Exception $ignored) {
        // Mantém ano atual se não for possível inspecionar os índices
    }

    return $currentYear;
}

function resolveLeagueId(PDO $pdo, string $league): ?int
{
    try {
        $stmt = $pdo->prepare('SELECT id FROM leagues WHERE name = ? LIMIT 1');
        $stmt->execute([$league]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && !empty($row['id']) ? (int)$row['id'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function buildTeamsLeagueFilter(PDO $pdo, string $league): array
{
    if (columnExists($pdo, 'teams', 'league_id')) {
        $leagueId = resolveLeagueId($pdo, $league);
        if ($leagueId) {
            return [
                'clause' => 'league = ? OR league_id = ?',
                'params' => [$league, $leagueId]
            ];
        }
    }

    return [
        'clause' => 'league = ?',
        'params' => [$league]
    ];
}

function fetchLeagueTeams(PDO $pdo, string $league): array
{
    $filter = buildTeamsLeagueFilter($pdo, $league);
    $stmt = $pdo->prepare("SELECT id FROM teams WHERE {$filter['clause']}");
    $stmt->execute($filter['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ensureLeagueSprintDefaults(PDO $pdo): void
{
    try {
        // INSERT IGNORE: só semeia o padrão pra liga sem linha nenhuma ainda —
        // nunca sobrescreve um max_seasons já existente (seja o seed anterior ou
        // uma configuração manual feita em Admin).
        $pdo->exec("
            INSERT IGNORE INTO league_sprint_config (league, max_seasons) VALUES
            ('ELITE', 25),
            ('NEXT', 20),
            ('RISE', 15),
            ('ROOKIE', 10)
        ");
    } catch (Exception $e) {
        error_log('Erro ao garantir league_sprint_config: ' . $e->getMessage());
    }
}

function ensureSprintStartYear(PDO $pdo, array $sprint, ?int $requestedStartYear, ?int $currentSeasonYear = null, ?int $currentSeasonNumber = null): int
{
    $startYear = (int)($sprint['start_year'] ?? 0);

    // Sprint que ainda não tem nenhuma temporada não começou de verdade: o ano
    // que o admin informar em "Iniciar Sprint" manda, mesmo que a linha já
    // tenha sido criada antes com outro ano. Com temporada já criada, o ano é
    // fixo — mexer nele bagunçaria os anos das temporadas existentes.
    if ($requestedStartYear && $requestedStartYear > 0 && $requestedStartYear !== $startYear) {
        $stmtTem = $pdo->prepare("SELECT COUNT(*) FROM seasons WHERE sprint_id = ?");
        $stmtTem->execute([$sprint['id']]);
        if ((int)$stmtTem->fetchColumn() === 0) {
            $pdo->prepare("UPDATE sprints SET start_year = ? WHERE id = ?")
                ->execute([$requestedStartYear, $sprint['id']]);
            return $requestedStartYear;
        }
    }

    if ($startYear > 0) {
        return $startYear;
    }

    if ($requestedStartYear && $requestedStartYear > 0) {
        $startYear = $requestedStartYear;
    } elseif ($currentSeasonYear && $currentSeasonNumber) {
        $startYear = $currentSeasonYear - $currentSeasonNumber + 1;
    } else {
        $startYear = (int)date('Y');
    }

    $stmtUpdate = $pdo->prepare("UPDATE sprints SET start_year = ? WHERE id = ?");
    $stmtUpdate->execute([$startYear, $sprint['id']]);

    return $startYear;
}

function calculateSeasonYear(int $startYear, int $seasonNumber): int
{
    return $startYear + $seasonNumber - 1;
}

/**
 * Congela a tática ativa de cada time da liga e desmarca "feito no jogo".
 *
 * O snapshot é o "antes": na temporada seguinte, o Admin compara a tática
 * atual com ele pra mostrar em vermelho o que o time mexeu. Sem isso não há
 * como saber o que mudou de uma temporada pra outra.
 */
function snapshotTaticasDaLiga(PDO $pdo, string $league): void
{
    $campos = [
        'starter_1_id', 'starter_2_id', 'starter_3_id', 'starter_4_id', 'starter_5_id',
        'bench_1_id', 'bench_2_id', 'bench_3_id', 'gleague_1_id', 'gleague_2_id',
        'pace', 'offensive_rebound', 'offensive_aggression', 'defensive_focus',
        'defensive_rebound', 'rotation_style', 'game_style', 'offense_style',
        'rotation_players', 'veteran_focus', 'technical_model', 'playbook', 'notes',
    ];

    $stmt = $pdo->prepare("
        SELECT tt.* FROM team_tactics tt
        JOIN teams t ON t.id = tt.team_id
        WHERE t.league = ? AND tt.is_active = 1
    ");
    $stmt->execute([$league]);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$linhas) return;

    $upd = $pdo->prepare("
        UPDATE team_tactics SET snapshot_json = ?, feito_no_jogo = 0
        WHERE team_id = ? AND slot = ?
    ");
    foreach ($linhas as $l) {
        $snap = [];
        foreach ($campos as $c) {
            $snap[$c] = $l[$c] ?? null;
        }
        $upd->execute([json_encode($snap, JSON_UNESCAPED_UNICODE), (int)$l['team_id'], $l['slot']]);
    }
}

function getPickWindowYears(int $startYear, int $seasonNumber, int $maxSeasons, int $horizon = 5): array
{
    $windowStart = $startYear + $seasonNumber;
    $windowEnd = $windowStart + $horizon - 1;

    if ($maxSeasons > 0) {
        $endYear = $startYear + $maxSeasons - 1;
        $lastPickYear = $endYear;

        if ($windowStart > $lastPickYear) {
            return [];
        }

        $windowEnd = min($windowEnd, $lastPickYear);
    }

    if ($windowEnd < $windowStart) {
        return [];
    }

    return range($windowStart, $windowEnd);
}

function syncAutoGeneratedPicks(PDO $pdo, string $league, array $teams, int $seasonId, array $targetYears, bool $reuseOutsideWindow = false): array
{
    $stats = ['created' => 0, 'renamed' => 0, 'deleted' => 0, 'kept' => 0];

    if (empty($teams) || empty($targetYears)) {
        return $stats;
    }

    $minTarget = min($targetYears);
    $maxTarget = max($targetYears);

    $teamFilter = buildTeamsLeagueFilter($pdo, $league);
    $stmtSelect = $pdo->prepare("
        SELECT * FROM picks
        WHERE original_team_id = ? AND round = ? AND team_id IN (SELECT id FROM teams WHERE {$teamFilter['clause']})
        ORDER BY season_year ASC, id ASC
    ");
    $stmtUpdate = $pdo->prepare("UPDATE picks SET season_year = ?, season_id = ?, auto_generated = 1 WHERE id = ?");
    $stmtInsert = $pdo->prepare("
        INSERT INTO picks (team_id, original_team_id, season_year, round, season_id, auto_generated, last_owner_team_id)
        VALUES (?, ?, ?, ?, ?, 1, ?)
    ");
    $stmtDelete = $pdo->prepare("DELETE FROM picks WHERE id = ?");

    foreach ($teams as $team) {
        foreach (['1', '2'] as $round) {
            $params = array_merge([$team['id'], $round], $teamFilter['params']);
            $stmtSelect->execute($params);
            $allPicks = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

            $occupiedYears = [];
            foreach ($allPicks as $pick) {
                $occupiedYears[(int)$pick['season_year']] = $pick;
            }

            $autoPicks = array_values(array_filter($allPicks, function ($p) use ($reuseOutsideWindow, $minTarget, $maxTarget) {
                if ((int)($p['auto_generated'] ?? 0) !== 1) {
                    return false;
                }

                if ($reuseOutsideWindow) {
                    return true;
                }

                $year = (int)$p['season_year'];
                return $year >= $minTarget && $year <= $maxTarget;
            }));

            $autoPicksOutside = [];
            if (!$reuseOutsideWindow) {
                foreach ($allPicks as $pick) {
                    if ((int)($pick['auto_generated'] ?? 0) === 1) {
                        $year = (int)$pick['season_year'];
                        if ($year < $minTarget || $year > $maxTarget) {
                            $autoPicksOutside[] = $pick;
                        }
                    }
                }
            }

            $usedAutoIds = [];

            foreach ($targetYears as $year) {
                if (isset($occupiedYears[$year])) {
                    if ((int)$occupiedYears[$year]['auto_generated'] === 1) {
                        $usedAutoIds[] = (int)$occupiedYears[$year]['id'];
                        $stats['kept']++;
                    }
                    continue;
                }

                $candidate = null;
                foreach ($autoPicks as $pick) {
                    if (!in_array((int)$pick['id'], $usedAutoIds, true)) {
                        $candidate = $pick;
                        break;
                    }
                }

                if ($candidate) {
                    $stmtUpdate->execute([$year, $seasonId, $candidate['id']]);
                    $usedAutoIds[] = (int)$candidate['id'];
                    $occupiedYears[$year] = $candidate;
                    $stats['renamed']++;
                } else {
                    $stmtInsert->execute([$team['id'], $team['id'], $year, $round, $seasonId, $team['id']]);
                    $stats['created']++;
                }
            }

            foreach ($autoPicks as $pick) {
                if (!in_array((int)$pick['id'], $usedAutoIds, true)) {
                    $stmtDelete->execute([$pick['id']]);
                    $stats['deleted']++;
                }
            }

            foreach ($autoPicksOutside as $pick) {
                $stmtDelete->execute([$pick['id']]);
                $stats['deleted']++;
            }
        }
    }

    return $stats;
}

$pdo = db();
// Garante que a tabela de configuração esteja com os valores atualizados
ensureLeagueSprintDefaults($pdo);

// ==================== ADMIN ONLY ACTIONS ====================
$adminActions = ['create_season', 'end_season', 'start_draft', 'end_draft', 'add_draft_player',
                 'update_draft_player', 'delete_draft_player', 'clear_draft_pool', 'assign_draft_pick',
                 'set_standings', 'set_playoff_results', 'set_awards', 'reset_teams', 'reset_sprint',
                 'adjust_picks', 'run_picks', 'register_pontuacao', 'advance_season', 'finalize_sprint',
                 'resync_season_points', 'audit_points_integrity', 'recalculate_points_check', 'debug_team_season_raw'];

if (in_array($action, $adminActions) && ($user['user_type'] ?? 'jogador') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
    exit;
}

/**
 * Sincroniza team_season_points com a soma de pontos de team_ranking_points
 * (regular_season + playoff + awards) para a temporada/liga indicada.
 * Chamada após set_standings e save_history para manter o card "Pontuação" atualizado.
 */
function syncTeamSeasonPoints(PDO $pdo, int $seasonId, string $league, int $sprintNumber, int $seasonNumber): void {
    // Pontos já gravados para esta temporada (necessário para calcular o delta em re-registros)
    $stmtOld = $pdo->prepare("SELECT team_id, points FROM team_season_points WHERE season_id = ?");
    $stmtOld->execute([$seasonId]);
    $oldPoints = [];
    foreach ($stmtOld->fetchAll(PDO::FETCH_ASSOC) as $r) $oldPoints[(int)$r['team_id']] = (int)$r['points'];

    $stmt = $pdo->prepare("
        SELECT trp.team_id,
               CONCAT(t.city, ' ', t.name) AS team_name,
               SUM(COALESCE(trp.regular_season_points, 0)) AS regular_points,
               SUM(COALESCE(trp.playoff_points,         0)) AS playoff_points_sum,
               SUM(COALESCE(trp.awards_points,          0)) AS awards_points_sum,
               SUM(
                   COALESCE(trp.regular_season_points, 0) +
                   COALESCE(trp.playoff_points,         0) +
                   COALESCE(trp.awards_points,          0)
               ) AS total_points
        FROM team_ranking_points trp
        LEFT JOIN teams t ON t.id = trp.team_id
        WHERE trp.season_id = ? AND trp.league = ?
        GROUP BY trp.team_id, t.city, t.name
        HAVING total_points > 0
    ");
    $stmt->execute([$seasonId, $league]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) return;

    // Garante que as colunas de detalhamento existem em team_season_points (mesmo padrao do history-points.php)
    foreach (['points_regular', 'points_playoffs', 'points_prizes'] as $col) {
        if (!columnExists($pdo, 'team_season_points', $col)) {
            $pdo->exec("ALTER TABLE team_season_points ADD COLUMN {$col} INT NOT NULL DEFAULT 0");
        }
    }

    $stmtUpsert = $pdo->prepare("
        INSERT INTO team_season_points
            (team_id, team_name, league, season_id, sprint_number, season_number, points, points_regular, points_playoffs, points_prizes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            points           = VALUES(points),
            points_regular   = VALUES(points_regular),
            points_playoffs  = VALUES(points_playoffs),
            points_prizes    = VALUES(points_prizes),
            team_name        = VALUES(team_name),
            updated_at       = NOW()
    ");
    $newPoints = [];
    foreach ($rows as $row) {
        $tid = (int)$row['team_id'];
        $pts = (int)$row['total_points'];
        $newPoints[$tid] = $pts;
        $stmtUpsert->execute([
            $tid, $row['team_name'] ?? 'Time Desconhecido', $league, $seasonId, $sprintNumber, $seasonNumber,
            $pts, (int)$row['regular_points'], (int)$row['playoff_points_sum'], (int)$row['awards_points_sum'],
        ]);
    }

    // Incrementa teams.ranking_points pelo delta (novo - antigo desta temporada).
    // Nunca recalcula do zero — o histórico é opcional e não afeta o acumulado.
    if (!columnExists($pdo, 'teams', 'ranking_points')) return;
    $stmtInc = $pdo->prepare("UPDATE teams SET ranking_points = GREATEST(0, COALESCE(ranking_points,0) + ?) WHERE id = ?");
    foreach (array_unique(array_merge(array_keys($newPoints), array_keys($oldPoints))) as $tid) {
        $delta = ($newPoints[$tid] ?? 0) - ($oldPoints[$tid] ?? 0);
        if ($delta !== 0) $stmtInc->execute([$delta, $tid]);
    }
}

/**
 * Recalcula teams.ranking_points como acumulado histórico de team_season_points.
 * Seguro para chamar múltiplas vezes — nunca duplica pontos.
 */
function recalcTeamsRankingPoints(PDO $pdo, string $league): void {
    if (!columnExists($pdo, 'teams', 'ranking_points')) return;
    // Recalcula como soma acumulada de todas as temporadas do time (idempotente).
    $pdo->prepare("
        UPDATE teams t
        SET t.ranking_points = COALESCE((
            SELECT SUM(tsp.points)
            FROM team_season_points tsp
            WHERE tsp.team_id = t.id
        ), 0)
        WHERE t.league = ?
    ")->execute([$league]);
}

function ensurePlayerSeasonLogTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS player_season_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        player_id   INT NOT NULL,
        player_name VARCHAR(120),
        league      VARCHAR(20),
        season_id   INT,
        season_number INT,
        sprint_number INT,
        year        INT,
        team_id     INT,
        team_name   VARCHAR(200),
        ovr         INT,
        age         INT,
        position    VARCHAR(20),
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_player_season (player_id, season_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function snapshotPlayersForSeason(PDO $pdo, int $seasonId, string $league): void {
    ensurePlayerSeasonLogTable($pdo);

    $stmtS = $pdo->prepare("
        SELECT s.season_number, s.year, sp.sprint_number, sp.start_year
        FROM seasons s LEFT JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.id = ? LIMIT 1
    ");
    $stmtS->execute([$seasonId]);
    $s = $stmtS->fetch(PDO::FETCH_ASSOC);
    if (!$s) return;

    $seasonNumber = (int)($s['season_number'] ?? 0);
    $sprintNumber = (int)($s['sprint_number'] ?? 0);
    $year         = $s['year'] ?? $s['start_year'] ?? null;

    $stmtP = $pdo->prepare("
        SELECT p.id AS player_id, p.name AS player_name, p.ovr, p.age, p.position,
               t.id AS team_id, CONCAT(t.city, ' ', t.name) AS team_name
        FROM players p JOIN teams t ON p.team_id = t.id
        WHERE t.league = ?
    ");
    $stmtP->execute([$league]);
    $players = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $ins = $pdo->prepare("
        INSERT INTO player_season_log
            (player_id, player_name, league, season_id, season_number, sprint_number, year, team_id, team_name, ovr, age, position)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            team_id=VALUES(team_id), team_name=VALUES(team_name),
            ovr=VALUES(ovr), age=VALUES(age), position=VALUES(position)
    ");
    foreach ($players as $p) {
        $ins->execute([
            (int)$p['player_id'], $p['player_name'], $league,
            $seasonId, $seasonNumber, $sprintNumber, $year,
            (int)$p['team_id'], $p['team_name'], (int)($p['ovr'] ?? 0), (int)($p['age'] ?? 0), $p['position'] ?? null
        ]);
    }
}

try {
    switch ($action) {
        // ========== ASSINATURA DE ESTADO (para auto-refresh de telas ao vivo) ==========
        // Combina o timestamp mais recente de mudança de temporada (seasons) com o
        // timestamp de mudança da janela de trocas (league_settings.trades_enabled).
        // Front-end faz polling e recarrega a tela quando a assinatura muda.
        case 'state_signature':
            $leagues = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
            $signatures = [];
            $stmtSeason = $pdo->prepare("SELECT GREATEST(COALESCE(MAX(created_at),0), COALESCE(MAX(updated_at),0)) AS ts FROM seasons WHERE league = ?");
            $stmtSettings = $pdo->prepare("SELECT trades_enabled, updated_at FROM league_settings WHERE league = ?");
            foreach ($leagues as $lg) {
                $stmtSeason->execute([$lg]);
                $seasonTs = $stmtSeason->fetchColumn() ?: '';
                $stmtSettings->execute([$lg]);
                $settingsRow = $stmtSettings->fetch();
                $tradesEnabled = $settingsRow['trades_enabled'] ?? 1;
                $settingsTs = $settingsRow['updated_at'] ?? '';
                $signatures[$lg] = md5($seasonTs . '|' . $tradesEnabled . '|' . $settingsTs);
            }
            echo json_encode(['success' => true, 'signatures' => $signatures]);
            break;

        // ========== DEFINIR CLASSIFICAÇÃO (STANDINGS) E PONTOS DA TEMPORADA REGULAR ==========
        case 'set_standings':
            if ($method !== 'POST') throw new Exception('Método inválido');

            // Somente admin
            if (($user['user_type'] ?? 'jogador') !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $seasonId = isset($input['season_id']) ? (int)$input['season_id'] : null;
            $standings = isset($input['standings']) && is_array($input['standings']) ? $input['standings'] : [];

            if (!$seasonId) throw new Exception('season_id é obrigatório');
            if (empty($standings)) throw new Exception('standings (array) é obrigatório');

            // Buscar liga da temporada
            $stmtSeason = $pdo->prepare("
                SELECT s.league, s.season_number,
                       COALESCE(sp.sprint_number, 1) AS sprint_number
                FROM seasons s
                LEFT JOIN sprints sp ON s.sprint_id = sp.id
                WHERE s.id = ?
            ");
            $stmtSeason->execute([$seasonId]);
            $seasonRow = $stmtSeason->fetch(PDO::FETCH_ASSOC);
            if (!$seasonRow) throw new Exception('Temporada não encontrada');
            $league       = $seasonRow['league'];
            $seasonNumber = (int)($seasonRow['season_number'] ?? 1);
            $sprintNumber = (int)($seasonRow['sprint_number'] ?? 1);

            // Garantir tabela season_standings
            $pdo->exec("CREATE TABLE IF NOT EXISTS season_standings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                season_id INT NOT NULL,
                team_id INT NOT NULL,
                position INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_season_team (season_id, team_id),
                INDEX idx_season_pos (season_id, position),
                FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $pdo->beginTransaction();
            try {
                // Limpar standings anteriores desta temporada
                $pdo->prepare('DELETE FROM season_standings WHERE season_id = ?')->execute([$seasonId]);

                // Mapa de pontos por posição
                // 1º: 4, 2º-4º: 3, 5º-8º: 2
                $pointsByPosition = function (int $pos): int {
                    if ($pos === 1) return 4;
                    if ($pos >= 2 && $pos <= 4) return 3;
                    if ($pos >= 5 && $pos <= 8) return 2;
                    return 0;
                };

                $stmtInsertStanding = $pdo->prepare('INSERT INTO season_standings (season_id, team_id, position) VALUES (?, ?, ?)');

                // Inserir standings e atualizar pontos da temporada regular
                $stmtUpsertPoints = $pdo->prepare("INSERT INTO team_ranking_points (
                        team_id, season_id, league, regular_season_position, regular_season_points
                    ) VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        league = VALUES(league),
                        regular_season_position = VALUES(regular_season_position),
                        regular_season_points = VALUES(regular_season_points)");

                // Aceita array de IDs (ordenado) ou array de objetos {team_id, position}
                $position = 1;
                foreach ($standings as $item) {
                    if (is_array($item)) {
                        $teamId = (int)($item['team_id'] ?? 0);
                        $pos = isset($item['position']) ? (int)$item['position'] : $position;
                    } else {
                        // item simples (id) indica ordem
                        $teamId = (int)$item;
                        $pos = $position;
                    }
                    if ($teamId <= 0) { $position++; continue; }

                    $stmtInsertStanding->execute([$seasonId, $teamId, $pos]);
                    $regularPoints = $pointsByPosition($pos);
                    $stmtUpsertPoints->execute([$teamId, $seasonId, $league, $pos, $regularPoints]);
                    $position++;
                }

                $pdo->commit();

                // Sincronizar team_season_points com o total acumulado
                syncTeamSeasonPoints($pdo, $seasonId, $league, $sprintNumber, $seasonNumber);

                echo json_encode(['success' => true, 'message' => 'Standings e pontos de temporada regular salvos']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
        // ========== MOEDAS POR TEMPORADA ==========
        case 'season_coins':
            $seasonId = $_GET['season_id'] ?? null;
            if (!$seasonId) throw new Exception('Season ID não especificado');

            if ($method === 'GET') {
                // Buscar times da temporada
                $stmt = $pdo->prepare('SELECT league FROM seasons WHERE id = ?');
                $stmt->execute([$seasonId]);
                $season = $stmt->fetch();
                if (!$season) throw new Exception('Temporada não encontrada');
                $league = $season['league'];

                $stmtTeams = $pdo->prepare('SELECT id, city, name, moedas FROM teams WHERE league = ? ORDER BY city, name');
                $stmtTeams->execute([$league]);
                $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'teams' => $teams, 'league' => $league]);
                break;
            }

            if ($method === 'POST') {
                if (($user['user_type'] ?? 'jogador') !== 'admin') {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                    exit;
                }
                $data = json_decode(file_get_contents('php://input'), true);
                $updates = $data['updates'] ?? [];
                if (!is_array($updates) || empty($updates)) throw new Exception('Nenhuma atualização enviada');

                $pdo->beginTransaction();
                try {
                    foreach ($updates as $item) {
                        $teamId = $item['team_id'] ?? null;
                        $moedas = $item['moedas'] ?? null;
                        if (!$teamId || $moedas === null) continue;
                        $stmt = $pdo->prepare('UPDATE teams SET moedas = ? WHERE id = ?');
                        $stmt->execute([(int)$moedas, $teamId]);
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                echo json_encode(['success' => true, 'message' => 'Moedas atualizadas']);
                break;
            }
            throw new Exception('Método não suportado');

        // ========== BUSCAR TEMPORADA ATUAL ==========
        case 'season_checklist':
            // O que ainda falta pra poder virar a temporada desta liga.
            $league = strtoupper((string)($_GET['league'] ?? ''));
            if (!in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
                throw new Exception('Liga inválida');
            }
            $stmtCk = $pdo->prepare("
                SELECT s.id, s.season_number, s.year, sp.sprint_number
                FROM seasons s JOIN sprints sp ON sp.id = s.sprint_id
                WHERE s.league = ? AND s.status != 'completed'
                ORDER BY s.id DESC LIMIT 1
            ");
            $stmtCk->execute([$league]);
            $seasonCk = $stmtCk->fetch(PDO::FETCH_ASSOC);
            if (!$seasonCk) {
                echo json_encode(['success' => true, 'season' => null, 'itens' => [], 'completo' => false]);
                break;
            }
            $itensCk = checklistDaTemporada($pdo, $league, $seasonCk);
            echo json_encode([
                'success'  => true,
                'season'   => $seasonCk,
                'itens'    => $itensCk,
                'completo' => checklistCompleto($itensCk),
            ]);
            break;

        case 'current_season':
            $league = $_GET['league'] ?? null;
            if (!$league) {
                throw new Exception('Liga não especificada');
            }
            
            $stmt = $pdo->prepare("
                SELECT s.*, sp.sprint_number, sp.status as sprint_status,
                       sp.start_year,
                       lsc.max_seasons as sprint_max_seasons
                FROM seasons s
                INNER JOIN sprints sp ON s.sprint_id = sp.id
                INNER JOIN league_sprint_config lsc ON s.league = lsc.league
                WHERE s.league = ? AND s.status != 'completed'
                ORDER BY s.id DESC LIMIT 1
            ");

            // Substituir por UPSERT para mesclar com pontos da temporada regular (se já existirem)
            $stmtInsertRanking = $pdo->prepare("
                INSERT INTO team_ranking_points 
                (team_id, season_id, league, 
                 playoff_champion, playoff_runner_up, playoff_conference_finals, 
                 playoff_second_round, playoff_first_round, playoff_points,
                 awards_count, awards_points)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    league = VALUES(league),
                    playoff_champion = VALUES(playoff_champion),
                    playoff_runner_up = VALUES(playoff_runner_up),
                    playoff_conference_finals = VALUES(playoff_conference_finals),
                    playoff_second_round = VALUES(playoff_second_round),
                    playoff_first_round = VALUES(playoff_first_round),
                    playoff_points = VALUES(playoff_points),
                    awards_count = VALUES(awards_count),
                    awards_points = VALUES(awards_points)
            ");
            $stmt->execute([$league]);
            $season = $stmt->fetch();
            
            echo json_encode(['success' => true, 'season' => $season]);
            break;

        // ========== LISTAR TODAS AS TEMPORADAS ==========
        case 'list_seasons':
            $league = $_GET['league'] ?? null;
            $where = $league ? "WHERE s.league = ?" : "";
            $params = $league ? [$league] : [];
            
            $stmt = $pdo->prepare("
                SELECT s.*, sp.sprint_number, sp.start_year,
                       lsc.max_seasons as sprint_max_seasons,
                       (SELECT COUNT(*) FROM draft_pool WHERE season_id = s.id) as draft_players_count
                FROM seasons s
                INNER JOIN sprints sp ON s.sprint_id = sp.id
                LEFT JOIN league_sprint_config lsc ON s.league = lsc.league
                $where
                ORDER BY s.id DESC
            ");
            $stmt->execute($params);
            $seasons = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'seasons' => $seasons]);
            break;

        // ========== CRIAR NOVA TEMPORADA (ADMIN) ==========
        case 'create_season':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            $league = isset($data['league']) ? strtoupper($data['league']) : null;
            
            if (!$league || !in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'])) {
                throw new Exception('Liga inválida');
            }
            
            $pdo->beginTransaction();
            
            $requestedYear = isset($data['season_year']) ? (int)$data['season_year'] : 0;
            $requestedStartYear = isset($data['start_year']) ? (int)$data['start_year'] : 0;
            
            // Buscar ou criar sprint atual
            $stmtSprint = $pdo->prepare("
                SELECT id, sprint_number, start_year FROM sprints
                WHERE league = ? AND status = 'active'
                ORDER BY id DESC LIMIT 1
            ");
            $stmtSprint->execute([$league]);
            $sprint = $stmtSprint->fetch();

            // "Iniciar Sprint" (o admin clica quando não há temporada em
            // andamento) pede um sprint NOVO. Sem isto, o código achava o
            // sprint antigo ainda marcado como ativo e tentava só acrescentar
            // mais uma temporada nele — com o ano preso ao ano dele, o que
            // rejeitava o ano informado e virava "Erro interno do servidor".
            if ($sprint && !empty($data['new_sprint'])) {
                $stmtTemAtiva = $pdo->prepare("
                    SELECT COUNT(*) FROM seasons WHERE sprint_id = ? AND status != 'completed'
                ");
                $stmtTemAtiva->execute([$sprint['id']]);

                // Só encerra o sprint anterior se ele de fato não tem mais
                // temporada em andamento — senão isto viraria um jeito
                // silencioso de abandonar um sprint no meio.
                if ((int)$stmtTemAtiva->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE sprints SET status = 'completed', end_date = CURDATE() WHERE id = ?")
                        ->execute([$sprint['id']]);
                    $sprint = false; // cai no ramo de criação abaixo
                } else {
                    // Sem isto o fluxo seguia adiante e falhava lá na frente com
                    // "o ano informado não bate", que não diz o que está errado.
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'error' => "A liga {$league} ainda tem uma temporada em andamento nesta sprint. "
                                 . 'Use "Avançar Temporada" ou finalize a sprint antes de começar outra.',
                    ]);
                    exit;
                }
            }

            if (!$sprint) {
                $startYear = $requestedStartYear ?: $requestedYear;
                if (!$startYear) {
                    throw new Exception('Informe o ano inicial do sprint (ex: 2016).');
                }

                // Numeração continua de onde a liga parou — fixar em 1 batia no
                // UNIQUE(league, sprint_number) quando a liga já teve sprints.
                $stmtProx = $pdo->prepare("SELECT COALESCE(MAX(sprint_number), 0) + 1 FROM sprints WHERE league = ?");
                $stmtProx->execute([$league]);
                $sprintNumber = (int)$stmtProx->fetchColumn();

                $stmtCreate = $pdo->prepare("
                    INSERT INTO sprints (league, sprint_number, start_year, start_date)
                    VALUES (?, ?, ?, CURDATE())
                ");
                $stmtCreate->execute([$league, $sprintNumber, $startYear]);
                $sprintId = $pdo->lastInsertId();
                $sprint = ['id' => $sprintId, 'start_year' => $startYear, 'sprint_number' => $sprintNumber];
            } else {
                $sprintId = $sprint['id'];
                $sprintNumber = $sprint['sprint_number'];
                $stmtLastSeason = $pdo->prepare("SELECT year, season_number FROM seasons WHERE sprint_id = ? ORDER BY id DESC LIMIT 1");
                $stmtLastSeason->execute([$sprintId]);
                $lastSeason = $stmtLastSeason->fetch(PDO::FETCH_ASSOC);
                $startYear = ensureSprintStartYear(
                    $pdo,
                    $sprint,
                    $requestedStartYear,
                    $lastSeason['year'] ?? null,
                    $lastSeason['season_number'] ?? null
                );
            }
            
            // Contar temporadas no sprint atual
            $stmtCount = $pdo->prepare("
                SELECT COUNT(*) as count FROM seasons WHERE sprint_id = ?
            ");
            $stmtCount->execute([$sprintId]);
            $seasonCount = $stmtCount->fetch()['count'];
            
            // Buscar limite de temporadas
            $stmtConfig = $pdo->prepare("SELECT max_seasons FROM league_sprint_config WHERE league = ?");
            $stmtConfig->execute([$league]);
            $maxSeasons = $stmtConfig->fetch()['max_seasons'];
            
            if ($seasonCount >= $maxSeasons) {
                throw new Exception("Sprint já completou o máximo de {$maxSeasons} temporadas. Inicie um novo sprint.");
            }
            
            // Criar nova temporada
            $seasonNumber = $seasonCount + 1;
            $year = calculateSeasonYear($startYear, $seasonNumber);

            if ($requestedYear > 0 && $requestedYear !== $year) {
                throw new Exception("O ano informado ({$requestedYear}) não bate com o ano esperado para esta sprint ({$year}). Ajuste o ano inicial do sprint se necessário.");
            }

            // Ano repetido entre sprints é permitido (um ciclo novo pode
            // recomeçar o calendário da liga). O que não pode repetir é a
            // temporada dentro do mesmo sprint.
            $stmtVerify = $pdo->prepare("SELECT COUNT(*) FROM seasons WHERE sprint_id = ? AND season_number = ?");
            $stmtVerify->execute([$sprintId, $seasonNumber]);
            if ((int)$stmtVerify->fetchColumn() > 0) {
                throw new Exception("A temporada {$seasonNumber} já existe neste sprint da liga {$league}.");
            }
            
            $stmtSeason = $pdo->prepare("
                INSERT INTO seasons (sprint_id, league, season_number, year, start_date, status, current_phase)
                VALUES (?, ?, ?, ?, CURDATE(), 'draft', 'draft')
            ");
            $stmtSeason->execute([$sprintId, $league, $seasonNumber, $year]);
            $seasonId = $pdo->lastInsertId();
            
            $teams = fetchLeagueTeams($pdo, $league);
            $pickStats = !empty($teams)
                ? syncAutoGeneratedPicks($pdo, $league, $teams, $seasonId, getPickWindowYears($startYear, $seasonNumber, (int)$maxSeasons))
                : ['created' => 0, 'renamed' => 0, 'deleted' => 0, 'kept' => 0];

            // Tática: guarda como ela está agora (o "antes" pra comparar depois)
            // e desmarca o "feito no jogo" — cada temporada começa do zero e o
            // admin precisa aplicar de novo dentro do jogo.
            try {
                snapshotTaticasDaLiga($pdo, $league);
            } catch (Throwable $e) {
                error_log('[create_season] snapshot de taticas: ' . $e->getMessage());
            }

            // Resetar moedas e contadores de FA (dispensas/contratações) da liga ao avançar temporada
            $pdo->prepare("UPDATE teams SET moedas = 0 WHERE league = ?")->execute([$league]);
            // Zera dispensas e contratações a cada temporada
            try {
                $pdo->prepare("UPDATE teams SET waivers_used = 0, fa_signings_used = 0 WHERE league = ?")->execute([$league]);
            } catch (Exception $e) {
                // Colunas podem não existir em instalações antigas; ignorar silenciosamente
            }

            // Zerar (resetar) o ciclo de trades a cada 2 temporadas
            // Definimos current_cycle = ceil(season_number / 2) para todos os times da liga
            // Assim, temporadas 1-2 usam ciclo 1, 3-4 ciclo 2, etc.
            try {
                $tradeCycle = (int)ceil($seasonNumber / 2);
                $stmtCycle = $pdo->prepare("UPDATE teams SET current_cycle = ? WHERE league = ?");
                $stmtCycle->execute([$tradeCycle, $league]);

                // Sincronizar trades_cycle sem zerar quando ainda nao inicializado
                $pdo->prepare("UPDATE teams SET trades_cycle = ? WHERE league = ? AND (trades_cycle IS NULL OR trades_cycle = 0)")
                    ->execute([$tradeCycle, $league]);

                // Resetar contador de trades somente quando o ciclo muda
                $pdo->prepare("UPDATE teams SET trades_used = 0, trades_cycle = ? WHERE league = ? AND trades_cycle <> ?")
                    ->execute([$tradeCycle, $league, $tradeCycle]);
            } catch (Exception $e) {
                // Se a coluna current_cycle/trades_* não existir, ignorar
            }

            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'season_id' => $seasonId,
                'message' => "Temporada {$seasonNumber} criada! Picks: {$pickStats['created']} novas, {$pickStats['renamed']} ajustadas, {$pickStats['deleted']} removidas."
            ]);
            break;

        // ========== AJUSTAR PICKS DO SPRINT (ADMIN) ==========
        case 'adjust_picks':
        case 'run_picks':
            if (!in_array($method, ['POST', 'GET'], true)) throw new Exception('Método inválido');

            $data = [];
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true) ?: [];
            } else {
                $data = $_GET;
            }

            $league = isset($data['league']) ? strtoupper((string)$data['league']) : null;
            $seasonId = isset($data['season_id']) ? (int)$data['season_id'] : 0;
            $requestedStartYear = isset($data['start_year']) ? (int)$data['start_year'] : 0;

            if (!$league) {
                throw new Exception('Liga não especificada');
            }

            $whereClause = $seasonId ? "s.id = ?" : "s.league = ? AND s.status != 'completed'";
            $params = $seasonId ? [$seasonId] : [$league];

            $stmtSeason = $pdo->prepare("
                SELECT s.*, sp.start_year, sp.id as sprint_id, sp.sprint_number
                FROM seasons s
                LEFT JOIN sprints sp ON s.sprint_id = sp.id
                WHERE $whereClause
                ORDER BY s.id DESC
                LIMIT 1
            ");
            $stmtSeason->execute($params);
            $season = $stmtSeason->fetch(PDO::FETCH_ASSOC);

            // Fallback: quando season_id vier com id da sprint por engano.
            if (!$season && $seasonId > 0) {
                $stmtSeasonBySprint = $pdo->prepare("
                    SELECT s.*, sp.start_year, sp.id as sprint_id, sp.sprint_number
                    FROM seasons s
                    LEFT JOIN sprints sp ON s.sprint_id = sp.id
                    WHERE s.sprint_id = ? AND s.league = ?
                    ORDER BY s.id DESC
                    LIMIT 1
                ");
                $stmtSeasonBySprint->execute([$seasonId, $league]);
                $season = $stmtSeasonBySprint->fetch(PDO::FETCH_ASSOC);
            }

            if (!$season) {
                throw new Exception('Temporada não encontrada para ajustar picks.');
            }

            if (!empty($season['league']) && strtoupper($season['league']) !== strtoupper($league)) {
                throw new Exception('A temporada informada não pertence à liga selecionada.');
            }

            $startYear = ensureSprintStartYear(
                $pdo,
                ['id' => $season['sprint_id'], 'start_year' => $season['start_year']],
                $requestedStartYear,
                $season['year'] ?? null,
                $season['season_number'] ?? null
            );

            $stmtConfig = $pdo->prepare("SELECT max_seasons FROM league_sprint_config WHERE league = ?");
            $stmtConfig->execute([$league]);
            $maxSeasons = (int)($stmtConfig->fetch()['max_seasons'] ?? 0);

            $targetYears = getPickWindowYears($startYear, (int)$season['season_number'], $maxSeasons);
            $teams = fetchLeagueTeams($pdo, $league);
            $stats = syncAutoGeneratedPicks($pdo, $league, $teams, (int)$season['id'], $targetYears, true);

            echo json_encode([
                'success' => true,
                'message' => 'Picks do sprint ajustadas.',
                'action' => $action,
                'target_years' => $targetYears,
                'stats' => $stats
            ]);
            break;

        // ========== BUSCAR JOGADORES DO DRAFT ==========
        case 'draft_players':
            $seasonId = $_GET['season_id'] ?? null;
            if (!$seasonId) throw new Exception('Season ID não especificado');
            
            $stmt = $pdo->prepare("
                SELECT 
                    dp.*,
                    CONCAT(t.city, ' ', t.name) as team_name
                FROM draft_pool dp
                LEFT JOIN teams t ON dp.drafted_by_team_id = t.id
                WHERE dp.season_id = ? 
                ORDER BY dp.draft_status ASC, dp.draft_order ASC, dp.ovr DESC, dp.name ASC
            ");
            $stmt->execute([$seasonId]);
            $players = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'players' => $players]);
            break;

        // ========== ADICIONAR JOGADOR NO DRAFT POOL (ADMIN) ==========
        case 'add_draft_player':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $seasonId = isset($data['season_id']) ? (int)$data['season_id'] : 0;
            $name = trim((string)($data['name'] ?? ''));
            $position = trim((string)($data['position'] ?? ''));
            $age = isset($data['age']) ? (int)$data['age'] : 0;
            $ovr = isset($data['ovr']) ? (int)$data['ovr'] : 0;

            if ($seasonId <= 0 || $name === '' || $position === '' || $age <= 0 || $ovr <= 0) {
                throw new Exception('season_id, name, position, age e ovr são obrigatórios');
            }

            $columns = ['season_id', 'name', 'position', 'age', 'ovr'];
            $values = [$seasonId, $name, $position, $age, $ovr];

            if (columnExists($pdo, 'draft_pool', 'secondary_position')) {
                $columns[] = 'secondary_position';
                $secondary = trim((string)($data['secondary_position'] ?? ''));
                $values[] = $secondary !== '' ? $secondary : null;
            }
            if (columnExists($pdo, 'draft_pool', 'photo_url')) {
                $columns[] = 'photo_url';
                $photoUrl = trim((string)($data['photo_url'] ?? ''));
                $values[] = $photoUrl !== '' ? $photoUrl : null;
            }
            if (columnExists($pdo, 'draft_pool', 'bio')) {
                $columns[] = 'bio';
                $values[] = $data['bio'] ?? null;
            }
            if (columnExists($pdo, 'draft_pool', 'strengths')) {
                $columns[] = 'strengths';
                $values[] = $data['strengths'] ?? null;
            }
            if (columnExists($pdo, 'draft_pool', 'weaknesses')) {
                $columns[] = 'weaknesses';
                $values[] = $data['weaknesses'] ?? null;
            }
            if (columnExists($pdo, 'draft_pool', 'pick_hint')) {
                $columns[] = 'pick_hint';
                $hint = isset($data['pick_hint']) && $data['pick_hint'] !== '' ? (int)$data['pick_hint'] : null;
                $values[] = $hint && $hint > 0 ? $hint : null;
            }

            $columnList = implode(', ', array_map(static fn($col) => "`{$col}`", $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));

            $stmt = $pdo->prepare("INSERT INTO draft_pool ({$columnList}) VALUES ({$placeholders})");
            $stmt->execute($values);
            
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

            // ========== ATRIBUIR JOGADOR DRAFTADO A UM TIME (ADMIN) ==========
            case 'assign_draft_pick':
                if ($method !== 'POST') throw new Exception('Método inválido');
            
                $data = json_decode(file_get_contents('php://input'), true);
            
                if (!isset($data['team_id']) || !isset($data['player_id'])) {
                    throw new Exception('team_id e player_id são obrigatórios');
                }
            
                $pdo->beginTransaction();
            
                // Buscar próximo draft_order
                $stmtOrder = $pdo->prepare("SELECT COALESCE(MAX(draft_order), 0) + 1 as next_order FROM draft_pool WHERE draft_status = 'drafted'");
                $stmtOrder->execute();
                $nextOrder = $stmtOrder->fetch()['next_order'];
            
                // Atualizar draft_pool
                $stmt = $pdo->prepare("
                    UPDATE draft_pool 
                    SET draft_status = 'drafted', drafted_by_team_id = ?, draft_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([$data['team_id'], $nextOrder, $data['player_id']]);
            
                // Adicionar jogador ao elenco do time
                $stmtPlayer = $pdo->prepare("SELECT * FROM draft_pool WHERE id = ?");
                $stmtPlayer->execute([$data['player_id']]);
                $draftPlayer = $stmtPlayer->fetch();
            
                $stmtInsert = $pdo->prepare("
                    INSERT INTO players (team_id, name, position, age, ovr, role, available_for_trade)
                    VALUES (?, ?, ?, ?, ?, 'Reserva', 0)
                ");
                $stmtInsert->execute([
                    $data['team_id'],
                    $draftPlayer['name'],
                    $draftPlayer['position'],
                    $draftPlayer['age'],
                    $draftPlayer['ovr']
                ]);
            
                $pdo->commit();
            
                echo json_encode(['success' => true]);
                break;
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['team_id']) || !isset($data['player_id'])) {
                throw new Exception('team_id e player_id são obrigatórios');
            }
            
            $pdo->beginTransaction();
            
            // Buscar próximo draft_order
            $stmtOrder = $pdo->prepare("SELECT COALESCE(MAX(draft_order), 0) + 1 as next_order FROM draft_pool WHERE draft_status = 'drafted'");
            $stmtOrder->execute();
            $nextOrder = $stmtOrder->fetch()['next_order'];
            
            // Atualizar draft_pool
            $stmt = $pdo->prepare("
                UPDATE draft_pool 
                SET draft_status = 'drafted', drafted_by_team_id = ?, draft_order = ?
                WHERE id = ?
            ");
            $stmt->execute([$data['team_id'], $nextOrder, $data['player_id']]);
            
            // Adicionar jogador ao elenco do time
            $stmtPlayer = $pdo->prepare("SELECT * FROM draft_pool WHERE id = ?");
            $stmtPlayer->execute([$data['player_id']]);
            $draftPlayer = $stmtPlayer->fetch();
            
            $stmtInsert = $pdo->prepare("
                INSERT INTO players (team_id, name, position, age, ovr, role, available_for_trade)
                VALUES (?, ?, ?, ?, ?, 'Reserva', 0)
            ");
            $stmtInsert->execute([
                $data['team_id'],
                $draftPlayer['name'],
                $draftPlayer['position'],
                $draftPlayer['age'],
                $draftPlayer['ovr']
            ]);
            
            $pdo->commit();
            
            echo json_encode(['success' => true]);
            break;

        // ========== ATUALIZAR PICK_HINT DE JOGADOR DO DRAFT (ADMIN) ==========
        case 'update_draft_player':
            if ($method !== 'POST') throw new Exception('Método inválido');
            $data = json_decode(file_get_contents('php://input'), true);
            $playerId = (int)($data['player_id'] ?? 0);
            if (!$playerId) throw new Exception('player_id é obrigatório');
            $hint = isset($data['pick_hint']) && $data['pick_hint'] !== '' ? (int)$data['pick_hint'] : null;
            $hint = ($hint && $hint > 0) ? $hint : null;
            $pdo->prepare('UPDATE draft_pool SET pick_hint = ? WHERE id = ?')->execute([$hint, $playerId]);
            echo json_encode(['success' => true]);
            break;

        // ========== REMOVER JOGADOR DO DRAFT (ADMIN) ==========
        case 'delete_draft_player':
            if ($method === 'DELETE') {
                $playerId = (int)($_GET['id'] ?? 0);
            } elseif ($method === 'POST') {
                $payload = json_decode(file_get_contents('php://input'), true);
                $playerId = isset($payload['player_id']) ? (int)$payload['player_id'] : 0;
            } else {
                throw new Exception('Método inválido');
            }

            if (!$playerId) {
                throw new Exception('player_id é obrigatório');
            }

            $stmtDelete = $pdo->prepare('DELETE FROM draft_pool WHERE id = ?');
            $stmtDelete->execute([$playerId]);

            echo json_encode(['success' => true, 'message' => 'Jogador removido do draft']);
            break;

        case 'clear_draft_pool':
            $payload = json_decode(file_get_contents('php://input'), true);
            $seasonId = isset($payload['season_id']) ? (int)$payload['season_id'] : 0;
            if (!$seasonId) throw new Exception('season_id é obrigatório');
            $pdo->prepare("DELETE FROM draft_pool WHERE season_id = ? AND draft_status = 'available'")->execute([$seasonId]);
            echo json_encode(['success' => true, 'message' => 'Pool limpo']);
            break;

        // ========== BUSCAR RANKING GLOBAL ==========
        case 'global_ranking':
            $stmt = $pdo->query("SELECT * FROM vw_global_ranking LIMIT 100");
            $ranking = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'ranking' => $ranking]);
            break;

        // ========== BUSCAR RANKING POR LIGA ==========
        case 'league_ranking':
            $league = $_GET['league'] ?? null;
            if (!$league) throw new Exception('Liga não especificada');
            
            $stmt = $pdo->prepare("SELECT * FROM vw_league_ranking WHERE league = ?");
            $stmt->execute([$league]);
            $ranking = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'ranking' => $ranking]);
            break;

        // ========== HISTÓRICO DE CAMPEÕES ==========
        case 'champions_history':
            $league = $_GET['league'] ?? null;
            $where = $league ? "WHERE league = ?" : "";
            $params = $league ? [$league] : [];
            
            $stmt = $pdo->prepare("SELECT * FROM vw_champions_history $where");
            $stmt->execute($params);
            $history = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'history' => $history]);
            break;

        // ========== HISTÓRICO COMPLETO COM PRÊMIOS ==========
        case 'full_history':
            $league = $_GET['league'] ?? null;
            
            // Buscar temporadas completas
            $whereClause = $league ? "WHERE s.league = ? AND s.status = 'completed'" : "WHERE s.status = 'completed'";
            $params = $league ? [$league] : [];
            
            $stmt = $pdo->prepare("
                SELECT s.id as season_id, s.season_number, s.year, s.league
                FROM seasons s
                $whereClause
                ORDER BY s.id DESC
            ");
            $stmt->execute($params);
            $seasons = $stmt->fetchAll();
            
            $result = [];
            foreach ($seasons as $season) {
                $seasonData = [
                    'id' => $season['season_id'],
                    'number' => $season['season_number'],
                    'year' => $season['year'],
                    'league' => $season['league'],
                    'champion' => null,
                    'runner_up' => null,
                    'awards' => []
                ];
                
                // Buscar campeão e vice
                $stmtPlayoffs = $pdo->prepare("
                    SELECT pr.position, t.id as team_id, t.city, t.name as team_name
                    FROM playoff_results pr
                    JOIN teams t ON pr.team_id = t.id
                    WHERE pr.season_id = ?
                ");
                $stmtPlayoffs->execute([$season['season_id']]);
                $playoffs = $stmtPlayoffs->fetchAll();
                
                foreach ($playoffs as $p) {
                    if ($p['position'] === 'champion') {
                        $seasonData['champion'] = ['team_id' => $p['team_id'], 'city' => $p['city'], 'name' => $p['team_name']];
                    } else if ($p['position'] === 'runner_up') {
                        $seasonData['runner_up'] = ['team_id' => $p['team_id'], 'city' => $p['city'], 'name' => $p['team_name']];
                    }
                }
                
                // Buscar prêmios individuais
                $stmtAwards = $pdo->prepare("
                    SELECT sa.award_type, sa.player_name, t.id as team_id, t.city, t.name as team_name
                    FROM season_awards sa
                    JOIN teams t ON sa.team_id = t.id
                    WHERE sa.season_id = ?
                ");
                $stmtAwards->execute([$season['season_id']]);
                $awards = $stmtAwards->fetchAll();
                
                foreach ($awards as $award) {
                    $seasonData['awards'][] = [
                        'type' => $award['award_type'],
                        'player' => $award['player_name'],
                        'team_id' => $award['team_id'],
                        'team_city' => $award['city'],
                        'team_name' => $award['team_name']
                    ];
                }
                
                $result[] = $seasonData;
            }
            
            echo json_encode(['success' => true, 'history' => $result]);
            break;

        // ========== SALVAR HISTÓRICO DA TEMPORADA ==========
            case 'save_history':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $input = json_decode(file_get_contents('php://input'), true);
            $seasonId = isset($input['season_id']) ? (int)$input['season_id'] : null;
            $champion = isset($input['champion']) ? (int)$input['champion'] : null;
            $runnerUp = isset($input['runner_up']) ? (int)$input['runner_up'] : null;
            
            // Arrays de IDs dos times eliminados
            $firstRound = isset($input['first_round_losses']) && is_array($input['first_round_losses']) ? array_map('intval', array_filter($input['first_round_losses'])) : [];
            $secondRound = isset($input['second_round_losses']) && is_array($input['second_round_losses']) ? array_map('intval', array_filter($input['second_round_losses'])) : [];
            $confFinal = isset($input['conference_final_losses']) && is_array($input['conference_final_losses']) ? array_map('intval', array_filter($input['conference_final_losses'])) : [];
            
            if (!$seasonId || !$champion || !$runnerUp) {
                throw new Exception('Dados incompletos: season_id, champion e runner_up são obrigatórios');
            }

            // Validar duplicações lógicas
            $allEliminated = array_merge($firstRound, $secondRound, $confFinal);
            if (count(array_unique($allEliminated)) !== count($allEliminated)) {
                throw new Exception('Um time não pode ser marcado em mais de uma fase eliminada');
            }
            if (in_array($champion, $allEliminated) || in_array($runnerUp, $allEliminated)) {
                throw new Exception('Não inclua campeão ou vice nas fases de eliminados');
            }

            // DDL fora da transação (ALTER/CREATE causam commit implícito no MySQL)
            ensurePlayerSeasonLogTable($pdo); // CREATE TABLE dentro de snapshotPlayersForSeason
            if (!columnExists($pdo, 'teams', 'ranking_titles')) {
                $pdo->exec("ALTER TABLE teams ADD COLUMN ranking_titles INT NOT NULL DEFAULT 0");
            }
            $stmtShCheck = $pdo->query("SHOW TABLES LIKE 'season_history'");
            if (!$stmtShCheck->fetch()) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS season_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    season_id INT NOT NULL,
                    league VARCHAR(20),
                    sprint_number INT,
                    season_number INT,
                    year INT,
                    champion_team_id INT NULL,
                    runner_up_team_id INT NULL,
                    mvp_player VARCHAR(100) NULL,
                    mvp_team_id INT NULL,
                    dpoy_player VARCHAR(100) NULL,
                    dpoy_team_id INT NULL,
                    mip_player VARCHAR(100) NULL,
                    mip_team_id INT NULL,
                    sixth_man_player VARCHAR(100) NULL,
                    sixth_man_team_id INT NULL,
                    roy_player VARCHAR(100) NULL,
                    roy_team_id INT NULL,
                    UNIQUE KEY unique_season_history (season_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            try {
                $chkRoy = $pdo->query("SHOW COLUMNS FROM season_history LIKE 'roy_player'");
                if (!$chkRoy->fetch()) {
                    $pdo->exec("ALTER TABLE season_history ADD COLUMN roy_player VARCHAR(100) NULL AFTER sixth_man_team_id");
                    $pdo->exec("ALTER TABLE season_history ADD COLUMN roy_team_id INT NULL AFTER roy_player");
                }
            } catch (Exception $ignored) {}

            $pdo->beginTransaction();
            
            // 1. Buscar informações da Liga (necessário para a tabela team_ranking_points)
            $stmtSeason = $pdo->prepare("
                SELECT s.league, s.season_number, s.year,
                       COALESCE(sp.sprint_number, 1) AS sprint_number
                FROM seasons s
                LEFT JOIN sprints sp ON s.sprint_id = sp.id
                WHERE s.id = ?
            ");
            $stmtSeason->execute([$seasonId]);
            $seasonData = $stmtSeason->fetch();

            if (!$seasonData) throw new Exception('Temporada não encontrada');
            $league        = $seasonData['league'];
            $seasonNumber  = (int)($seasonData['season_number'] ?? 1);
            $sprintNumber  = (int)($seasonData['sprint_number'] ?? 1);

            // 2. Salvar Tabelas Auxiliares (Playoff Results e Awards)
            // (Mantemos essa parte pois ela alimenta a exibição visual do histórico)
            $pdo->prepare("DELETE FROM playoff_results WHERE season_id = ?")->execute([$seasonId]);
            // Só limpa os prêmios simples; os estendidos (finals_mvp, all_nba_*, all_def_*)
            // têm cadastro próprio e não podem ser apagados aqui.
            $pdo->prepare("DELETE FROM season_awards WHERE season_id = ? AND award_type IN ('mvp','dpoy','mip','6th_man','roy')")->execute([$seasonId]);
            
            $stmtPlayoff = $pdo->prepare("INSERT INTO playoff_results (season_id, team_id, position) VALUES (?, ?, ?)");
            $stmtPlayoff->execute([$seasonId, $champion, 'champion']);
            $stmtPlayoff->execute([$seasonId, $runnerUp, 'runner_up']);
            foreach ($firstRound as $tid) $stmtPlayoff->execute([$seasonId, $tid, 'first_round']);
            foreach ($secondRound as $tid) $stmtPlayoff->execute([$seasonId, $tid, 'second_round']);
            foreach ($confFinal as $tid) $stmtPlayoff->execute([$seasonId, $tid, 'conference_final']);
            
            // Inserir prêmios na tabela auxiliar
            $stmtAward = $pdo->prepare("INSERT INTO season_awards (season_id, team_id, award_type, player_name) VALUES (?, ?, ?, ?)");
            $awardTypes = ['mvp', 'dpoy', 'mip', 'sixth_man', 'roy'];
            $awardsMap = []; // Para usar no cálculo de pontos depois
            
            foreach ($awardTypes as $type) {
                $teamKey = $type . '_team_id'; // ex: mvp_team_id
                if (!empty($input[$type]) && !empty($input[$teamKey])) {
                    $tId = (int)$input[$teamKey];
                    $stmtAward->execute([$seasonId, $tId, ($type == 'sixth_man' ? '6th_man' : $type), $input[$type]]);
                    
                    // Contabilizar para o ranking
                    if (!isset($awardsMap[$tId])) {
                        $awardsMap[$tId] = 0;
                    }
                    $awardsMap[$tId]++; // +1 prêmio para este time
                }
            }

            // 3. Pontuação do ranking: removida do fluxo automático.
            //    Use o endpoint 'set_season_points' para registrar pontos manualmente.

            // Função helper para iniciar o time no array se não existir
            $initTeam = function($tId) use (&$teamStats) {
                if (!isset($teamStats[$tId])) {
                    $teamStats[$tId] = [
                        'playoff_champion' => 0,
                        'playoff_runner_up' => 0,
                        'playoff_conference_finals' => 0,
                        'playoff_second_round' => 0,
                        'playoff_first_round' => 0,
                        'playoff_points' => 0,
                        'awards_count' => 0,
                        'awards_points' => 0
                    ];
                }
            };

            // Processar Campeão (cumulativo conforme regra: 1ª(1) + Semi(+2=3) + F.Conf(+3=6) + Vice(+2=8) + Campeão(+3=11))
            $initTeam($champion);
            $teamStats[$champion]['playoff_champion'] = 1;
            $teamStats[$champion]['playoff_points'] = 11;

            // Processar Vice (8 pontos)
            $initTeam($runnerUp);
            $teamStats[$runnerUp]['playoff_runner_up'] = 1;
            $teamStats[$runnerUp]['playoff_points'] = 8;

            // Processar Finais de Conferência (6 pontos)
            foreach ($confFinal as $tid) {
                $initTeam($tid);
                $teamStats[$tid]['playoff_conference_finals'] = 1;
                $teamStats[$tid]['playoff_points'] = 6;
            }

            // Processar 2ª Rodada (3 pontos)
            foreach ($secondRound as $tid) {
                $initTeam($tid);
                $teamStats[$tid]['playoff_second_round'] = 1;
                $teamStats[$tid]['playoff_points'] = 3;
            }

            // Processar 1ª Rodada (1 ponto)
            foreach ($firstRound as $tid) {
                $initTeam($tid);
                $teamStats[$tid]['playoff_first_round'] = 1;
                $teamStats[$tid]['playoff_points'] = 1;
            }

            // Processar Prêmios (1 ponto cada)
            foreach ($awardsMap as $tid => $count) {
                $initTeam($tid);
                $teamStats[$tid]['awards_count'] = $count;
                $teamStats[$tid]['awards_points'] = $count * 1; // 1 ponto por prêmio
            }

            // Agora fazemos o INSERT final para cada time
            $stmtInsertRanking = $pdo->prepare("
                INSERT INTO team_ranking_points 
                (team_id, season_id, league, 
                 playoff_champion, playoff_runner_up, playoff_conference_finals, 
                 playoff_second_round, playoff_first_round, playoff_points,
                 awards_count, awards_points)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($teamStats as $tid => $stats) {
                $stmtInsertRanking->execute([
                    $tid, 
                    $seasonId, 
                    $league,
                    $stats['playoff_champion'],
                    $stats['playoff_runner_up'],
                    $stats['playoff_conference_finals'],
                    $stats['playoff_second_round'],
                    $stats['playoff_first_round'],
                    $stats['playoff_points'],
                    $stats['awards_count'],
                    $stats['awards_points']
                ]);
            }

            // 4. Somar +1 título ao time campeão.
            $pdo->prepare("UPDATE teams SET ranking_titles = COALESCE(ranking_titles, 0) + 1 WHERE id = ?")
                ->execute([$champion]);

            // Atualizar Hall da Fama: +1 título para o campeão
            $stmtHofCheck = $pdo->prepare("SHOW TABLES LIKE 'hall_of_fame'");
            $stmtHofCheck->execute();
            if ($stmtHofCheck->rowCount() > 0) {
                $stmtHofTeam = $pdo->prepare("
                    SELECT CONCAT(t.city, ' ', t.name) AS team_name, u.name AS gm_name
                    FROM teams t
                    LEFT JOIN users u ON u.id = t.user_id
                    WHERE t.id = ? LIMIT 1
                ");
                $stmtHofTeam->execute([$champion]);
                $hofTeam = $stmtHofTeam->fetch(PDO::FETCH_ASSOC);

                if ($hofTeam) {
                    $stmtHofExists = $pdo->prepare("SELECT id FROM hall_of_fame WHERE team_id = ? AND league = ? LIMIT 1");
                    $stmtHofExists->execute([$champion, $league]);
                    if ($stmtHofExists->fetch()) {
                        $pdo->prepare("UPDATE hall_of_fame SET titles = titles + 1 WHERE team_id = ? AND league = ?")
                            ->execute([$champion, $league]);
                    } else {
                        $pdo->prepare("INSERT INTO hall_of_fame (team_id, team_name, league, gm_name, titles, is_active) VALUES (?, ?, ?, ?, 1, 1)")
                            ->execute([$champion, $hofTeam['team_name'], $league, $hofTeam['gm_name'] ?? '']);
                    }
                }
            }
            
            // 5. Salvar no season_history (histórico oficial: campeão, vice e prêmios individuais)
            $pdo->prepare("
                INSERT INTO season_history
                    (season_id, league, sprint_number, season_number, year,
                     champion_team_id, runner_up_team_id,
                     mvp_player, mvp_team_id,
                     dpoy_player, dpoy_team_id,
                     mip_player, mip_team_id,
                     sixth_man_player, sixth_man_team_id,
                     roy_player, roy_team_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    league              = VALUES(league),
                    sprint_number       = VALUES(sprint_number),
                    season_number       = VALUES(season_number),
                    year                = VALUES(year),
                    champion_team_id    = VALUES(champion_team_id),
                    runner_up_team_id   = VALUES(runner_up_team_id),
                    mvp_player          = VALUES(mvp_player),
                    mvp_team_id         = VALUES(mvp_team_id),
                    dpoy_player         = VALUES(dpoy_player),
                    dpoy_team_id        = VALUES(dpoy_team_id),
                    mip_player          = VALUES(mip_player),
                    mip_team_id         = VALUES(mip_team_id),
                    sixth_man_player    = VALUES(sixth_man_player),
                    sixth_man_team_id   = VALUES(sixth_man_team_id),
                    roy_player          = VALUES(roy_player),
                    roy_team_id         = VALUES(roy_team_id)
            ")->execute([
                $seasonId,
                $league,
                $sprintNumber,
                $seasonNumber,
                (int)($seasonData['year'] ?? date('Y')),
                $champion,
                $runnerUp,
                $input['mvp']       ?? null,
                !empty($input['mvp_team_id'])       ? (int)$input['mvp_team_id']       : null,
                $input['dpoy']      ?? null,
                !empty($input['dpoy_team_id'])      ? (int)$input['dpoy_team_id']      : null,
                $input['mip']       ?? null,
                !empty($input['mip_team_id'])       ? (int)$input['mip_team_id']       : null,
                $input['sixth_man'] ?? null,
                !empty($input['sixth_man_team_id']) ? (int)$input['sixth_man_team_id'] : null,
                $input['roy']       ?? null,
                !empty($input['roy_team_id'])       ? (int)$input['roy_team_id']       : null,
            ]);

            // Marcar temporada como completa
            $pdo->prepare("UPDATE seasons SET status = 'completed' WHERE id = ?")->execute([$seasonId]);

            $pdo->commit();

            // Fora da transação: snapshot usa CREATE TABLE IF NOT EXISTS (DDL = commit implícito)
            snapshotPlayersForSeason($pdo, $seasonId, $league);

            // Sincronizar team_season_points com total acumulado (regular + playoff + prêmios)
            syncTeamSeasonPoints($pdo, $seasonId, $league, $sprintNumber, $seasonNumber);

            echo json_encode(['success' => true, 'message' => 'Histórico salvo!']);
            break;

        // ========== VERIFICAR SE HISTÓRICO FOI REGISTRADO ==========
        case 'check_season_history':
            $seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 0;
            if (!$seasonId) throw new Exception('season_id é obrigatório');
            $stmtChk = $pdo->prepare("SELECT id FROM season_history WHERE season_id = ? LIMIT 1");
            $stmtChk->execute([$seasonId]);
            echo json_encode(['success' => true, 'registered' => (bool)$stmtChk->fetch()]);
            break;

        // ========== REGISTRAR PONTUAÇÃO (SEM AVANÇAR TEMPORADA) ==========
        case 'register_pontuacao':
            if ($method !== 'POST') throw new Exception('Método inválido');

            $input = json_decode(file_get_contents('php://input'), true);
            $seasonId = isset($input['season_id']) ? (int)$input['season_id'] : null;
            $champion = isset($input['champion']) ? (int)$input['champion'] : null;
            $runnerUp = isset($input['runner_up']) ? (int)$input['runner_up'] : null;

            $firstRound  = isset($input['first_round_losses'])       && is_array($input['first_round_losses'])       ? array_map('intval', array_filter($input['first_round_losses']))       : [];
            $secondRound = isset($input['second_round_losses'])      && is_array($input['second_round_losses'])      ? array_map('intval', array_filter($input['second_round_losses']))      : [];
            $confFinal   = isset($input['conference_final_losses'])  && is_array($input['conference_final_losses'])  ? array_map('intval', array_filter($input['conference_final_losses']))  : [];

            if (!$seasonId || !$champion || !$runnerUp) throw new Exception('Dados incompletos: season_id, champion e runner_up são obrigatórios');

            $allEliminated = array_merge($firstRound, $secondRound, $confFinal);
            if (count(array_unique($allEliminated)) !== count($allEliminated)) throw new Exception('Um time não pode ser marcado em mais de uma fase eliminada');
            if (in_array($champion, $allEliminated) || in_array($runnerUp, $allEliminated)) throw new Exception('Não inclua campeão ou vice nas fases de eliminados');

            ensurePlayerSeasonLogTable($pdo);
            if (!columnExists($pdo, 'teams', 'ranking_titles')) $pdo->exec("ALTER TABLE teams ADD COLUMN ranking_titles INT NOT NULL DEFAULT 0");
            $stmtShCheck = $pdo->query("SHOW TABLES LIKE 'season_history'");
            if (!$stmtShCheck->fetch()) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS season_history (
                    id INT AUTO_INCREMENT PRIMARY KEY, season_id INT NOT NULL, league VARCHAR(20),
                    sprint_number INT, season_number INT, year INT,
                    champion_team_id INT NULL, runner_up_team_id INT NULL,
                    mvp_player VARCHAR(100) NULL, mvp_team_id INT NULL,
                    dpoy_player VARCHAR(100) NULL, dpoy_team_id INT NULL,
                    mip_player VARCHAR(100) NULL, mip_team_id INT NULL,
                    sixth_man_player VARCHAR(100) NULL, sixth_man_team_id INT NULL,
                    roy_player VARCHAR(100) NULL, roy_team_id INT NULL,
                    nba_cup_team_id INT NULL,
                    UNIQUE KEY unique_season_history (season_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            try {
                $chkRoy = $pdo->query("SHOW COLUMNS FROM season_history LIKE 'roy_player'");
                if (!$chkRoy->fetch()) {
                    $pdo->exec("ALTER TABLE season_history ADD COLUMN roy_player VARCHAR(100) NULL AFTER sixth_man_team_id");
                    $pdo->exec("ALTER TABLE season_history ADD COLUMN roy_team_id INT NULL AFTER roy_player");
                }
                $chkNbaCup = $pdo->query("SHOW COLUMNS FROM season_history LIKE 'nba_cup_team_id'");
                if (!$chkNbaCup->fetch()) {
                    $afterColumn = columnExists($pdo, 'season_history', 'roy_team_id') ? 'roy_team_id' : 'sixth_man_team_id';
                    $pdo->exec("ALTER TABLE season_history ADD COLUMN nba_cup_team_id INT NULL AFTER {$afterColumn}");
                }
            } catch (Exception $ignored) {}

            // Garantir tabela de classificação (posição final de TODOS os times por temporada).
            // DDL causa commit implícito no MySQL, então roda antes de beginTransaction.
            $pdo->exec("CREATE TABLE IF NOT EXISTS season_standings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                season_id INT NOT NULL,
                team_id INT NOT NULL,
                position INT NOT NULL,
                conference VARCHAR(20) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_season_team (season_id, team_id),
                INDEX idx_season_pos (season_id, position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            try {
                $chkConf = $pdo->query("SHOW COLUMNS FROM season_standings LIKE 'conference'");
                if (!$chkConf->fetch()) {
                    $pdo->exec("ALTER TABLE season_standings ADD COLUMN conference VARCHAR(20) NULL AFTER position");
                }
            } catch (Exception $ignored) {}

            $pdo->beginTransaction();
            $stmtSeason2 = $pdo->prepare("SELECT s.league, s.season_number, s.year, COALESCE(sp.sprint_number, 1) AS sprint_number FROM seasons s LEFT JOIN sprints sp ON s.sprint_id = sp.id WHERE s.id = ?");
            $stmtSeason2->execute([$seasonId]);
            $seasonData2 = $stmtSeason2->fetch();
            if (!$seasonData2) throw new Exception('Temporada não encontrada');
            $league2       = $seasonData2['league'];
            $seasonNumber2 = (int)($seasonData2['season_number'] ?? 1);
            $sprintNumber2 = (int)($seasonData2['sprint_number'] ?? 1);
            $nbaCupTeamId2 = ($league2 === 'ELITE' && !empty($input['nba_cup_team_id'])) ? (int)$input['nba_cup_team_id'] : null;

            $pdo->prepare("DELETE FROM playoff_results WHERE season_id = ?")->execute([$seasonId]);
            // Só limpa os prêmios simples; os estendidos (finals_mvp, all_nba_*, all_def_*)
            // têm cadastro próprio e não podem ser apagados aqui.
            $pdo->prepare("DELETE FROM season_awards WHERE season_id = ? AND award_type IN ('mvp','dpoy','mip','6th_man','roy')")->execute([$seasonId]);

            $stmtPO2 = $pdo->prepare("INSERT INTO playoff_results (season_id, team_id, position) VALUES (?, ?, ?)");
            $stmtPO2->execute([$seasonId, $champion, 'champion']);
            $stmtPO2->execute([$seasonId, $runnerUp, 'runner_up']);
            foreach ($firstRound  as $tid) $stmtPO2->execute([$seasonId, $tid, 'first_round']);
            foreach ($secondRound as $tid) $stmtPO2->execute([$seasonId, $tid, 'second_round']);
            foreach ($confFinal   as $tid) $stmtPO2->execute([$seasonId, $tid, 'conference_final']);

            $stmtAw2 = $pdo->prepare("INSERT INTO season_awards (season_id, team_id, award_type, player_name) VALUES (?, ?, ?, ?)");
            $awardsMap2 = [];
            foreach (['mvp', 'dpoy', 'mip', 'sixth_man', 'roy'] as $atype) {
                $tKey = $atype . '_team_id';
                if (!empty($input[$tKey])) {
                    $tId2 = (int)$input[$tKey];
                    $playerName = !empty($input[$atype]) ? $input[$atype] : null;
                    $stmtAw2->execute([$seasonId, $tId2, ($atype === 'sixth_man' ? '6th_man' : $atype), $playerName]);
                    $awardsMap2[$tId2] = ($awardsMap2[$tId2] ?? 0) + 1;
                }
            }

            $teamStats2 = [];
            $initT2 = function($tid) use (&$teamStats2) {
                if (!isset($teamStats2[$tid])) $teamStats2[$tid] = ['playoff_champion'=>0,'playoff_runner_up'=>0,'playoff_conference_finals'=>0,'playoff_second_round'=>0,'playoff_first_round'=>0,'playoff_points'=>0,'awards_count'=>0,'awards_points'=>0];
            };
            $initT2($champion); $teamStats2[$champion]['playoff_champion'] = 1; $teamStats2[$champion]['playoff_points'] = 11;
            $initT2($runnerUp); $teamStats2[$runnerUp]['playoff_runner_up'] = 1; $teamStats2[$runnerUp]['playoff_points'] = 8;
            foreach ($confFinal   as $tid) { $initT2($tid); $teamStats2[$tid]['playoff_conference_finals'] = 1; $teamStats2[$tid]['playoff_points'] = 6; }
            foreach ($secondRound as $tid) { $initT2($tid); $teamStats2[$tid]['playoff_second_round'] = 1;      $teamStats2[$tid]['playoff_points'] = 3; }
            foreach ($firstRound  as $tid) { $initT2($tid); $teamStats2[$tid]['playoff_first_round'] = 1;       $teamStats2[$tid]['playoff_points'] = 1; }
            foreach ($awardsMap2  as $tid => $cnt) { $initT2($tid); $teamStats2[$tid]['awards_count'] = $cnt; $teamStats2[$tid]['awards_points'] = $cnt; }
            if ($nbaCupTeamId2) {
                $initT2($nbaCupTeamId2);
                $teamStats2[$nbaCupTeamId2]['awards_count'] += 1;
                $teamStats2[$nbaCupTeamId2]['awards_points'] += 2;
            }

            $stmtRk2 = $pdo->prepare("INSERT INTO team_ranking_points (team_id,season_id,league,playoff_champion,playoff_runner_up,playoff_conference_finals,playoff_second_round,playoff_first_round,playoff_points,awards_count,awards_points) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE playoff_champion=VALUES(playoff_champion),playoff_runner_up=VALUES(playoff_runner_up),playoff_conference_finals=VALUES(playoff_conference_finals),playoff_second_round=VALUES(playoff_second_round),playoff_first_round=VALUES(playoff_first_round),playoff_points=VALUES(playoff_points),awards_count=VALUES(awards_count),awards_points=VALUES(awards_points)");
            foreach ($teamStats2 as $tid => $s2) $stmtRk2->execute([$tid,$seasonId,$league2,$s2['playoff_champion'],$s2['playoff_runner_up'],$s2['playoff_conference_finals'],$s2['playoff_second_round'],$s2['playoff_first_round'],$s2['playoff_points'],$s2['awards_count'],$s2['awards_points']]);

            $pdo->prepare("UPDATE teams SET ranking_titles = COALESCE(ranking_titles,0)+1 WHERE id = ?")->execute([$champion]);

            // Campeão já registrado anteriormente pra essa temporada (se houver), pra manter o
            // Hall da Fama consistente mesmo se o admin corrigir o campeão depois de já ter registrado.
            $stmtPrevChampion = $pdo->prepare("SELECT champion_team_id FROM season_history WHERE season_id = ?");
            $stmtPrevChampion->execute([$seasonId]);
            $prevChampionRaw = $stmtPrevChampion->fetchColumn();
            $prevChampion = ($prevChampionRaw !== false && $prevChampionRaw !== null) ? (int)$prevChampionRaw : null;

            $pdo->prepare("INSERT INTO season_history (season_id,league,sprint_number,season_number,year,champion_team_id,runner_up_team_id,mvp_player,mvp_team_id,dpoy_player,dpoy_team_id,mip_player,mip_team_id,sixth_man_player,sixth_man_team_id,roy_player,roy_team_id,nba_cup_team_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE league=VALUES(league),sprint_number=VALUES(sprint_number),season_number=VALUES(season_number),year=VALUES(year),champion_team_id=VALUES(champion_team_id),runner_up_team_id=VALUES(runner_up_team_id),mvp_player=VALUES(mvp_player),mvp_team_id=VALUES(mvp_team_id),dpoy_player=VALUES(dpoy_player),dpoy_team_id=VALUES(dpoy_team_id),mip_player=VALUES(mip_player),mip_team_id=VALUES(mip_team_id),sixth_man_player=VALUES(sixth_man_player),sixth_man_team_id=VALUES(sixth_man_team_id),roy_player=VALUES(roy_player),roy_team_id=VALUES(roy_team_id),nba_cup_team_id=VALUES(nba_cup_team_id)")->execute([$seasonId,$league2,$sprintNumber2,$seasonNumber2,(int)($seasonData2['year']??date('Y')),$champion,$runnerUp,$input['mvp']??null,!empty($input['mvp_team_id'])?(int)$input['mvp_team_id']:null,$input['dpoy']??null,!empty($input['dpoy_team_id'])?(int)$input['dpoy_team_id']:null,$input['mip']??null,!empty($input['mip_team_id'])?(int)$input['mip_team_id']:null,$input['sixth_man']??null,!empty($input['sixth_man_team_id'])?(int)$input['sixth_man_team_id']:null,$input['roy']??null,!empty($input['roy_team_id'])?(int)$input['roy_team_id']:null,$nbaCupTeamId2]);

            // Hall da Fama: soma o título pro campeão (por liga), e se o campeão dessa
            // temporada estiver sendo corrigido, tira o título do campeão antigo primeiro.
            if ($prevChampion !== $champion) {
                if ($prevChampion !== null) {
                    hallOfFameRemoveTitle($pdo, $prevChampion, $league2);
                }
                hallOfFameAddTitle($pdo, $champion, $league2);
            }

            // Classificação final de TODOS os times (não só os 8 do playoff).
            // standings_leste / standings_oeste chegam ordenados (1º, 2º, ...) por conferência.
            $standingsLeste = (isset($input['standings_leste']) && is_array($input['standings_leste'])) ? $input['standings_leste'] : [];
            $standingsOeste = (isset($input['standings_oeste']) && is_array($input['standings_oeste'])) ? $input['standings_oeste'] : [];
            if ($standingsLeste || $standingsOeste) {
                $pdo->prepare("DELETE FROM season_standings WHERE season_id = ?")->execute([$seasonId]);
                $stmtStanding = $pdo->prepare("INSERT INTO season_standings (season_id, team_id, position, conference) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE position = VALUES(position), conference = VALUES(conference)");
                $seenStandingTeams = [];
                $insertStandings = function(array $list, string $conf) use ($stmtStanding, $seasonId, &$seenStandingTeams) {
                    $pos = 1;
                    foreach ($list as $tid) {
                        $tid = (int)$tid;
                        if ($tid <= 0 || isset($seenStandingTeams[$tid])) continue;
                        $seenStandingTeams[$tid] = true;
                        $stmtStanding->execute([$seasonId, $tid, $pos, $conf]);
                        $pos++;
                    }
                };
                $insertStandings($standingsLeste, 'LESTE');
                $insertStandings($standingsOeste, 'OESTE');
            }

            $pdo->commit();
            snapshotPlayersForSeason($pdo, $seasonId, $league2);
            syncTeamSeasonPoints($pdo, $seasonId, $league2, $sprintNumber2, $seasonNumber2);
            echo json_encode(['success' => true, 'message' => 'Pontuação registrada!']);
            break;

        // ========== RE-SINCRONIZAR PONTOS JA REGISTRADOS (BACKFILL) ==========
        // Reaproveita os dados ja corretos em team_ranking_points para preencher/corrigir
        // o detalhamento (points_regular/points_playoffs/points_prizes) em team_season_points,
        // sem precisar re-registrar campeao/vice/premiacoes.
        case 'resync_season_points':
            if ($method !== 'POST') throw new Exception('Método inválido');
            $input = json_decode(file_get_contents('php://input'), true);
            $seasonId = isset($input['season_id']) ? (int)$input['season_id'] : 0;
            if (!$seasonId) throw new Exception('season_id é obrigatório');

            $stmtSeason3 = $pdo->prepare("SELECT s.league, s.season_number, COALESCE(sp.sprint_number, 1) AS sprint_number FROM seasons s LEFT JOIN sprints sp ON s.sprint_id = sp.id WHERE s.id = ?");
            $stmtSeason3->execute([$seasonId]);
            $seasonData3 = $stmtSeason3->fetch(PDO::FETCH_ASSOC);
            if (!$seasonData3) throw new Exception('Temporada não encontrada');

            syncTeamSeasonPoints($pdo, $seasonId, $seasonData3['league'], (int)$seasonData3['sprint_number'], (int)$seasonData3['season_number']);
            echo json_encode(['success' => true, 'message' => 'Pontuação re-sincronizada']);
            break;

        // ========== AUDITORIA — SOMENTE LEITURA, NÃO ALTERA NADA ==========
        // 1) Times/temporadas com linhas duplicadas em team_ranking_points (causa de soma inflada)
        // 2) Times cujo total acumulado (teams.ranking_points) nao bate com a soma de team_season_points
        case 'audit_points_integrity':
            $league = $_REQUEST['league'] ?? null;
            if (!$league) throw new Exception('league é obrigatória');

            $stmtDup = $pdo->prepare("
                SELECT trp.season_id, s.season_number, COALESCE(sp.sprint_number,1) AS sprint_number, s.year,
                       trp.team_id, CONCAT(t.city,' ',t.name) AS team_name,
                       COUNT(*) AS row_count,
                       SUM(COALESCE(trp.regular_season_points,0)) AS reg_sum,
                       SUM(COALESCE(trp.playoff_points,0)) AS po_sum,
                       SUM(COALESCE(trp.awards_points,0)) AS pr_sum
                FROM team_ranking_points trp
                LEFT JOIN teams t ON t.id = trp.team_id
                LEFT JOIN seasons s ON s.id = trp.season_id
                LEFT JOIN sprints sp ON s.sprint_id = sp.id
                WHERE trp.league = ?
                GROUP BY trp.season_id, trp.team_id, s.season_number, sp.sprint_number, s.year, t.city, t.name
                HAVING row_count > 1
                ORDER BY trp.season_id, row_count DESC
            ");
            $stmtDup->execute([$league]);
            $duplicates = $stmtDup->fetchAll(PDO::FETCH_ASSOC);

            $rankingMismatches = [];
            if (columnExists($pdo, 'teams', 'ranking_points')) {
                $stmtMis = $pdo->prepare("
                    SELECT t.id AS team_id, CONCAT(t.city,' ',t.name) AS team_name,
                           COALESCE(t.ranking_points,0) AS current_total,
                           COALESCE(SUM(tsp.points),0) AS sum_season_points
                    FROM teams t
                    LEFT JOIN team_season_points tsp ON tsp.team_id = t.id
                    WHERE t.league = ?
                    GROUP BY t.id, t.city, t.name, t.ranking_points
                    HAVING COALESCE(t.ranking_points,0) <> COALESCE(SUM(tsp.points),0)
                    ORDER BY ABS(COALESCE(t.ranking_points,0) - COALESCE(SUM(tsp.points),0)) DESC
                ");
                $stmtMis->execute([$league]);
                $rankingMismatches = $stmtMis->fetchAll(PDO::FETCH_ASSOC);
            }

            // Temporadas registradas cujo team_season_points.points diverge do que team_ranking_points somaria hoje.
            // So considera temporadas que REALMENTE tem linhas em team_ranking_points (registradas pelo wizard novo);
            // temporadas antigas registradas pelo sistema legado (sem linhas em team_ranking_points) sao ignoradas
            // aqui, pois recomputed_points=0 nesse caso nao indica bug, so um caminho de registro diferente.
            $stmtSeasonDrift = $pdo->prepare("
                SELECT tsp.season_id, tsp.team_id, CONCAT(t.city,' ',t.name) AS team_name,
                       tsp.points AS stored_points,
                       SUM(COALESCE(trp.regular_season_points,0)+COALESCE(trp.playoff_points,0)+COALESCE(trp.awards_points,0)) AS recomputed_points,
                       COUNT(trp.id) AS trp_row_count
                FROM team_season_points tsp
                LEFT JOIN teams t ON t.id = tsp.team_id
                INNER JOIN team_ranking_points trp ON trp.season_id = tsp.season_id AND trp.team_id = tsp.team_id
                WHERE tsp.league = ?
                GROUP BY tsp.season_id, tsp.team_id, t.city, t.name, tsp.points
                HAVING tsp.points <> SUM(COALESCE(trp.regular_season_points,0)+COALESCE(trp.playoff_points,0)+COALESCE(trp.awards_points,0))
                ORDER BY tsp.season_id
            ");
            $stmtSeasonDrift->execute([$league]);
            $seasonDrift = $stmtSeasonDrift->fetchAll(PDO::FETCH_ASSOC);

            // Cobertura por categoria: quais temporadas tem cada categoria (seed/playoff/premios) zerada
            // em team_ranking_points, para confirmar que a divergencia vem de uma categoria nunca preenchida.
            $stmtCoverage = $pdo->prepare("
                SELECT trp.season_id, s.season_number, COALESCE(sp.sprint_number,1) AS sprint_number, s.year,
                       SUM(COALESCE(trp.regular_season_points,0)) AS regular_total,
                       SUM(COALESCE(trp.playoff_points,0)) AS playoff_total,
                       SUM(COALESCE(trp.awards_points,0)) AS awards_total
                FROM team_ranking_points trp
                LEFT JOIN seasons s ON s.id = trp.season_id
                LEFT JOIN sprints sp ON s.sprint_id = sp.id
                WHERE trp.league = ?
                GROUP BY trp.season_id, s.season_number, sp.sprint_number, s.year
                ORDER BY trp.season_id
            ");
            $stmtCoverage->execute([$league]);
            $seasonCoverage = $stmtCoverage->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'league' => $league,
                'duplicate_rows' => $duplicates,
                'ranking_mismatches' => $rankingMismatches,
                'season_drift' => $seasonDrift,
                'season_coverage' => $seasonCoverage,
            ]);
            break;

        // ========== RECALCULO OFICIAL (SOMENTE LEITURA) ==========
        // Recalcula os pontos do zero a partir das tabelas de origem (season_standings,
        // playoff_results, season_awards, season_history) usando a formula oficial das
        // diretrizes, e compara com o total ja salvo em team_season_points. Nao altera nada.
        case 'recalculate_points_check':
            $league = $_REQUEST['league'] ?? null;
            if (!$league) throw new Exception('league é obrigatória');

            $stmtSeasons = $pdo->prepare("
                SELECT s.id AS season_id, s.season_number, COALESCE(sp.sprint_number,1) AS sprint_number, s.year
                FROM seasons s
                LEFT JOIN sprints sp ON s.sprint_id = sp.id
                WHERE s.league = ?
                ORDER BY s.id
            ");
            $stmtSeasons->execute([$league]);
            $seasonsList = $stmtSeasons->fetchAll(PDO::FETCH_ASSOC);

            $stmtStored = $pdo->prepare("SELECT team_id, points FROM team_season_points WHERE season_id = ? AND league = ?");
            $stmtStanding = $pdo->prepare("SELECT team_id, position FROM season_standings WHERE season_id = ?");
            $stmtPlayoff = $pdo->prepare("SELECT team_id, position FROM playoff_results WHERE season_id = ?");
            $stmtAwards = $pdo->prepare("SELECT team_id, COUNT(*) AS c FROM season_awards WHERE season_id = ? GROUP BY team_id");
            $stmtCup = $pdo->prepare("SELECT nba_cup_team_id FROM season_history WHERE season_id = ?");
            $stmtTeamName = $pdo->prepare("SELECT CONCAT(city,' ',name) AS nm FROM teams WHERE id = ?");

            $seedPts = function (int $pos): int {
                if ($pos === 1) return 4;
                if ($pos >= 2 && $pos <= 4) return 3;
                if ($pos >= 5 && $pos <= 8) return 2;
                return 0;
            };
            $playoffPts = ['champion' => 11, 'runner_up' => 8, 'conference_final' => 6, 'second_round' => 3, 'first_round' => 1];

            $results = [];
            $teamNameCache = [];
            foreach ($seasonsList as $season) {
                $seasonId = (int)$season['season_id'];

                $stmtStored->execute([$seasonId, $league]);
                $stored = [];
                foreach ($stmtStored->fetchAll(PDO::FETCH_ASSOC) as $r) $stored[(int)$r['team_id']] = (int)$r['points'];
                if (empty($stored)) continue; // temporada nao registrada, nada a comparar

                $stmtStanding->execute([$seasonId]);
                $standingRows = $stmtStanding->fetchAll(PDO::FETCH_ASSOC);

                $stmtPlayoff->execute([$seasonId]);
                $playoffRows = $stmtPlayoff->fetchAll(PDO::FETCH_ASSOC);

                $stmtAwards->execute([$seasonId]);
                $awardsRows = $stmtAwards->fetchAll(PDO::FETCH_ASSOC);

                // Temporada legada sem nenhum dado bruto (playoff/standings/premios) -- nao ha o que comparar,
                // pular para nao gerar falsos positivos de divergencia.
                if (empty($standingRows) && empty($playoffRows) && empty($awardsRows)) continue;

                $stmtCup->execute([$seasonId]);
                $cupTeamId = $stmtCup->fetchColumn();

                $recomputed = [];
                foreach ($standingRows as $r) {
                    $tid = (int)$r['team_id'];
                    $recomputed[$tid] = ($recomputed[$tid] ?? 0) + $seedPts((int)$r['position']);
                }
                foreach ($playoffRows as $r) {
                    $tid = (int)$r['team_id'];
                    $recomputed[$tid] = ($recomputed[$tid] ?? 0) + ($playoffPts[$r['position']] ?? 0);
                }
                foreach ($awardsRows as $r) {
                    $tid = (int)$r['team_id'];
                    $recomputed[$tid] = ($recomputed[$tid] ?? 0) + (int)$r['c'];
                }
                if ($league === 'ELITE' && $cupTeamId) {
                    $tid = (int)$cupTeamId;
                    $recomputed[$tid] = ($recomputed[$tid] ?? 0) + 2;
                }

                $allTeamIds = array_unique(array_merge(array_keys($stored), array_keys($recomputed)));
                foreach ($allTeamIds as $tid) {
                    $storedPts = $stored[$tid] ?? 0;
                    $recomputedPts = $recomputed[$tid] ?? 0;
                    if ($storedPts === $recomputedPts) continue;
                    if (!isset($teamNameCache[$tid])) {
                        $stmtTeamName->execute([$tid]);
                        $teamNameCache[$tid] = $stmtTeamName->fetchColumn() ?: "Time #$tid";
                    }
                    $results[] = [
                        'season_id' => $seasonId,
                        'sprint_number' => (int)$season['sprint_number'],
                        'season_number' => (int)$season['season_number'],
                        'year' => $season['year'],
                        'team_id' => $tid,
                        'team_name' => $teamNameCache[$tid],
                        'stored_points' => $storedPts,
                        'recomputed_points' => $recomputedPts,
                        'diff' => $storedPts - $recomputedPts,
                        'has_standing_data' => !empty($standingRows),
                    ];
                }
            }

            echo json_encode(['success' => true, 'league' => $league, 'divergences' => $results]);
            break;

        // ========== DEBUG — DADOS BRUTOS DE UM TIME/TEMPORADA (SOMENTE LEITURA) ==========
        case 'debug_team_season_raw':
            $seasonId = (int)($_REQUEST['season_id'] ?? 0);
            $teamId = (int)($_REQUEST['team_id'] ?? 0);
            if (!$seasonId || !$teamId) throw new Exception('season_id e team_id são obrigatórios');

            $stmtA = $pdo->prepare('SELECT * FROM season_standings WHERE season_id = ? AND team_id = ?');
            $stmtA->execute([$seasonId, $teamId]);
            $standing = $stmtA->fetchAll(PDO::FETCH_ASSOC);

            $stmtB = $pdo->prepare('SELECT * FROM playoff_results WHERE season_id = ? AND team_id = ?');
            $stmtB->execute([$seasonId, $teamId]);
            $playoff = $stmtB->fetchAll(PDO::FETCH_ASSOC);

            $stmtC = $pdo->prepare('SELECT * FROM season_awards WHERE season_id = ? AND team_id = ?');
            $stmtC->execute([$seasonId, $teamId]);
            $awards = $stmtC->fetchAll(PDO::FETCH_ASSOC);

            $stmtD = $pdo->prepare('SELECT * FROM team_ranking_points WHERE season_id = ? AND team_id = ?');
            $stmtD->execute([$seasonId, $teamId]);
            $rankingPoints = $stmtD->fetchAll(PDO::FETCH_ASSOC);

            $stmtE = $pdo->prepare('SELECT * FROM team_season_points WHERE season_id = ? AND team_id = ?');
            $stmtE->execute([$seasonId, $teamId]);
            $seasonPoints = $stmtE->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'season_standings' => $standing,
                'playoff_results' => $playoff,
                'season_awards' => $awards,
                'team_ranking_points' => $rankingPoints,
                'team_season_points' => $seasonPoints,
            ]);
            break;

        // ========== AVANÇAR TEMPORADA (MARCAR COMO COMPLETA) ==========
        case 'advance_season':
            if ($method !== 'POST') throw new Exception('Método inválido');
            $input = json_decode(file_get_contents('php://input'), true);
            $seasonId = isset($input['season_id']) ? (int)$input['season_id'] : 0;
            if (!$seasonId) throw new Exception('season_id é obrigatório');
            $stmtAdv = $pdo->prepare("SELECT id, league FROM seasons WHERE id = ? LIMIT 1");
            $stmtAdv->execute([$seasonId]);
            $advSeason = $stmtAdv->fetch(PDO::FETCH_ASSOC);
            if (!$advSeason) throw new Exception('Temporada não encontrada');
            // Verificar se histórico foi registrado
            $stmtHistCheck = $pdo->prepare("SELECT id FROM season_history WHERE season_id = ? LIMIT 1");
            $stmtHistCheck->execute([$seasonId]);
            if (!$stmtHistCheck->fetch()) throw new Exception('Registre a pontuação antes de avançar a temporada');
            $pdo->prepare("UPDATE seasons SET status = 'completed' WHERE id = ?")->execute([$seasonId]);
            snapshotPlayersForSeason($pdo, $seasonId, $advSeason['league']);
            // Tática reabre automaticamente pra todos ao virar a temporada — remove
            // qualquer fechamento manual anterior do admin, volta pro corte diário padrão.
            try {
                $pdo->prepare("UPDATE tactic_edit_windows SET manual_closed = 0, manual_open_until = NULL WHERE league = ?")
                    ->execute([$advSeason['league']]);
            } catch (Throwable $e) {
                error_log('[advance_season] reset tactic_edit_windows: ' . $e->getMessage());
            }
            echo json_encode(['success' => true, 'message' => 'Temporada marcada como concluída']);
            break;

        // ========== FINALIZAR SPRINT (fecha o ciclo, congela o histórico, abre um novo) ==========
        case 'finalize_sprint':
            if ($method !== 'POST') throw new Exception('Método inválido');
            $input = json_decode(file_get_contents('php://input'), true);
            $league = isset($input['league']) ? strtoupper((string)$input['league']) : null;
            if (!$league || !in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
                throw new Exception('Liga inválida');
            }
            $requestedStartYear = isset($input['start_year']) ? (int)$input['start_year'] : 0;
            if ($requestedStartYear !== 0 && ($requestedStartYear < 1900 || $requestedStartYear > 2200)) {
                // Resposta direta: o catch geral desta API troca a mensagem por
                // um "Erro interno do servidor" que não ajuda quem digitou errado.
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Ano inicial inválido — informe um ano entre 1900 e 2200.']);
                exit;
            }

            // A última temporada ativa da liga — deve ser de fato a última do sprint atual
            $stmtFinSeason = $pdo->prepare("
                SELECT s.id, s.season_number, s.sprint_id, s.year, sp.sprint_number
                FROM seasons s
                JOIN sprints sp ON sp.id = s.sprint_id
                WHERE s.league = ? AND s.status != 'completed'
                ORDER BY s.id DESC LIMIT 1
            ");
            $stmtFinSeason->execute([$league]);
            $finSeason = $stmtFinSeason->fetch(PDO::FETCH_ASSOC);
            if (!$finSeason) throw new Exception('Nenhuma temporada ativa encontrada para esta liga');

            $stmtMaxSeasons = $pdo->prepare("SELECT max_seasons FROM league_sprint_config WHERE league = ?");
            $stmtMaxSeasons->execute([$league]);
            $maxSeasons = (int)($stmtMaxSeasons->fetchColumn() ?: 0);
            if ($maxSeasons <= 0 || (int)$finSeason['season_number'] < $maxSeasons) {
                throw new Exception('Esta liga ainda não chegou na última temporada do sprint — use "Avançar Temporada".');
            }

            $stmtHistCheck = $pdo->prepare("SELECT id FROM season_history WHERE season_id = ? LIMIT 1");
            $stmtHistCheck->execute([$finSeason['id']]);
            if (!$stmtHistCheck->fetch()) throw new Exception('Registre a pontuação antes de finalizar o sprint');

            $seasonId = (int)$finSeason['id'];
            $sprintId = (int)$finSeason['sprint_id'];
            $finishedSprintNumber = (int)$finSeason['sprint_number'];
            $nextSprintNumber = $finishedSprintNumber + 1;
            $newSeasonId = null;

            // O ano inicial do novo sprint. Sem escolha do admin, continua a
            // contagem da liga (ano da última temporada + 1).
            $newStartYear = ($requestedStartYear >= 1900 && $requestedStartYear <= 2200)
                ? $requestedStartYear
                : (int)$finSeason['year'] + 1;

            // Repetir o ano de um sprint anterior é permitido de propósito: um
            // ciclo novo pode recomeçar o calendário da liga no mesmo ano. A
            // migração troca o UNIQUE(year, league) por UNIQUE(sprint_id,
            // season_number), que é a identidade real da temporada. Aqui só
            // garante que o banco já está com a restrição nova antes de mexer
            // em qualquer coisa destrutiva.
            $anoNovaTemporada = calculateSeasonYear($newStartYear, 1);
            $temIndiceAntigo = false;
            try {
                foreach ($pdo->query("SHOW INDEX FROM seasons")->fetchAll(PDO::FETCH_ASSOC) as $ix) {
                    if (($ix['Key_name'] ?? '') === 'uniq_season_year_league') { $temIndiceAntigo = true; break; }
                }
            } catch (Throwable $e) {}

            if ($temIndiceAntigo) {
                $stmtAnoLivre = $pdo->prepare("
                    SELECT s.id FROM seasons s
                    JOIN sprints sp ON sp.id = s.sprint_id
                    WHERE s.league = ? AND s.year = ? AND sp.sprint_number <> ?
                    LIMIT 1
                ");
                $stmtAnoLivre->execute([$league, $anoNovaTemporada, $nextSprintNumber]);
                if ($stmtAnoLivre->fetch()) {
                    $stmtUltimoAno = $pdo->prepare("SELECT COALESCE(MAX(year), 0) FROM seasons WHERE league = ?");
                    $stmtUltimoAno->execute([$league]);
                    $sugestao = (int)$stmtUltimoAno->fetchColumn() + 1;

                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'error' => "A liga {$league} já tem uma temporada no ano {$anoNovaTemporada}. "
                                 . "Escolha outro ano inicial — o primeiro livre é {$sugestao}.",
                        'suggested_start_year' => $sugestao,
                    ]);
                    exit;
                }
            }

            // congelarRankingDaSprint() e snapshotPlayersForSeason() fazem CREATE TABLE IF NOT
            // EXISTS (ranking_snapshots / player_season_log) — DDL sempre causa commit implícito
            // no MySQL/InnoDB, mesmo quando a tabela já existe e o CREATE não muda nada (mesma
            // razão pela qual save_history, mais acima neste arquivo, já chama snapshotPlayers-
            // ForSeason fora de qualquer transação). As duas rodam ANTES da transação — e antes
            // do DELETE FROM players — porque snapshotam o estado atual (elenco/ranking) que a
            // transação abaixo vai apagar/zerar.
            snapshotPlayersForSeason($pdo, $seasonId, $league);
            congelarRankingDaSprint($pdo, $league, 'Fim da sprint');

            $pdo->beginTransaction();
            try {
                // 1. Fecha a última temporada do sprint (mesmo passo que advance_season já faz)
                $pdo->prepare("UPDATE seasons SET status = 'completed' WHERE id = ?")->execute([$seasonId]);

                // 2. Fecha o sprint
                $pdo->prepare("UPDATE sprints SET status = 'completed', end_date = CURDATE() WHERE id = ?")->execute([$sprintId]);

                // 3. Zera o estado "vivo" da liga pro novo ciclo. NÃO apaga seasons/sprints
                // antigos nem season_history/playoff_results/season_awards/team_ranking_points/
                // ranking_snapshots/player_season_log — tudo isso fica como histórico consultável.
                $pdo->prepare("DELETE p FROM players p INNER JOIN teams t ON p.team_id = t.id WHERE t.league = ?")
                    ->execute([$league]);
                $pdo->prepare("DELETE FROM trades WHERE from_team_id IN (SELECT id FROM teams WHERE league = ?)
                                                   OR to_team_id IN (SELECT id FROM teams WHERE league = ?)")
                    ->execute([$league, $league]);
                $pdo->prepare("DELETE dp FROM draft_pool dp INNER JOIN seasons s ON dp.season_id = s.id WHERE s.sprint_id = ?")
                    ->execute([$sprintId]);
                $pdo->prepare("DELETE ss FROM season_standings ss INNER JOIN seasons s ON ss.season_id = s.id WHERE s.sprint_id = ?")
                    ->execute([$sprintId]);
                $pdo->prepare("DELETE fao FROM free_agent_offers fao INNER JOIN free_agents fa ON fao.free_agent_id = fa.id WHERE fa.league = ?")
                    ->execute([$league]);
                $pdo->prepare("DELETE FROM free_agents WHERE league = ?")->execute([$league]);
                // picks não tem coluna league própria — escopo pelo dono atual da pick
                $pdo->prepare("DELETE p FROM picks p INNER JOIN teams t ON p.team_id = t.id WHERE t.league = ?")
                    ->execute([$league]);

                // Tática de cada time referencia diretamente os player_id do elenco que
                // acabou de ser apagado (titulares/banco/G-League) — sem isso, a config
                // antiga (inclusive estilo de jogo/playbook) carregaria pro novo sprint.
                try {
                    $pdo->prepare("DELETE tt FROM team_tactics tt INNER JOIN teams t ON tt.team_id = t.id WHERE t.league = ?")
                        ->execute([$league]);
                } catch (Throwable $e) {
                    error_log('[finalize_sprint] limpar team_tactics: ' . $e->getMessage());
                }

                // moedas é a única destas colunas que create_season já mexe sem
                // proteção (prova de que sempre existe) — as demais variam entre
                // instalações, então checa antes de incluir no UPDATE.
                $teamUpdates = ['moedas = 0'];
                foreach (['waivers_used', 'fa_signings_used', 'tapas', 'ranking_points', 'ranking_titles', 'current_cycle', 'trades_cycle', 'trades_used'] as $col) {
                    if (columnExists($pdo, 'teams', $col)) $teamUpdates[] = "{$col} = 0";
                }
                $pdo->prepare('UPDATE teams SET ' . implode(', ', $teamUpdates) . ' WHERE league = ?')->execute([$league]);

                // 4. Cria o novo sprint (sprint_number seguinte) e a temporada 1 dele.
                // O ano continua de onde a sprint anterior parou (não é o ano civil real
                // — a liga já pode estar em qualquer ano fictício, ex. 2044) — evita
                // colidir com o UNIQUE (year, league) de temporadas antigas preservadas.
                // Idempotente por (league, sprint_number)/(sprint_id, season_number): se uma
                // tentativa anterior já tiver criado o sprint/temporada novos (ex. falhou
                // depois disso), reaproveita em vez de tentar inserir de novo e colidir.
                // $newStartYear já foi calculado e validado antes da transação.
                $stmtExistingSprint = $pdo->prepare("SELECT id FROM sprints WHERE league = ? AND sprint_number = ?");
                $stmtExistingSprint->execute([$league, $nextSprintNumber]);
                $newSprintId = (int)($stmtExistingSprint->fetchColumn() ?: 0);
                if (!$newSprintId) {
                    $pdo->prepare("INSERT INTO sprints (league, sprint_number, start_year, start_date) VALUES (?, ?, ?, CURDATE())")
                        ->execute([$league, $nextSprintNumber, $newStartYear]);
                    $newSprintId = (int)$pdo->lastInsertId();
                }

                $newSeasonNumber = 1;
                $newYear = calculateSeasonYear($newStartYear, $newSeasonNumber);
                $stmtExistingSeason = $pdo->prepare("SELECT id FROM seasons WHERE sprint_id = ? AND season_number = ?");
                $stmtExistingSeason->execute([$newSprintId, $newSeasonNumber]);
                $newSeasonId = (int)($stmtExistingSeason->fetchColumn() ?: 0);
                if (!$newSeasonId) {
                    $pdo->prepare("INSERT INTO seasons (sprint_id, league, season_number, year, start_date, status, current_phase)
                                   VALUES (?, ?, ?, ?, CURDATE(), 'draft', 'draft')")
                        ->execute([$newSprintId, $league, $newSeasonNumber, $newYear]);
                    $newSeasonId = (int)$pdo->lastInsertId();
                }

                $teams = fetchLeagueTeams($pdo, $league);
                if (!empty($teams)) {
                    syncAutoGeneratedPicks($pdo, $league, $teams, $newSeasonId, getPickWindowYears($newStartYear, $newSeasonNumber, $maxSeasons));
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[finalize_sprint] ' . $e->getMessage());
                // DIAGNÓSTICO TEMPORÁRIO: expõe a mensagem real (o catch externo do
                // arquivo sempre troca por "Erro interno do servidor.") — reverter
                // depois de identificar a causa do 400 em produção.
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Erro ao finalizar o sprint: ' . $e->getMessage()]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'season_id' => $newSeasonId,
                'sprint_number' => $nextSprintNumber,
                'message' => "Sprint {$finishedSprintNumber} finalizada! Sprint {$nextSprintNumber} criada — configure o Draft Inicial."
            ]);
            break;

            // ========== DEFINIR PONTOS MANUAIS DA TEMPORADA (ADMIN) ==========
            case 'set_season_points':
                if ($method !== 'POST') throw new Exception('Método inválido');

                // Somente admin pode ajustar
                if (($user['user_type'] ?? 'jogador') !== 'admin') {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                    exit;
                }

                $input = json_decode(file_get_contents('php://input'), true);
                $seasonId = isset($input['season_id']) ? (int)$input['season_id'] : null;
                $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];

                if (!$seasonId) throw new Exception('season_id é obrigatório');

                $pdo->beginTransaction();

                // Limpar pontos desta temporada antes de gravar
                $pdo->prepare("DELETE FROM team_ranking_points WHERE season_id = ?")->execute([$seasonId]);

                $stmtInsert = $pdo->prepare("INSERT INTO team_ranking_points (team_id, season_id, points, reason) VALUES (?, ?, ?, ?)");

                foreach ($items as $row) {
                    $teamId = isset($row['team_id']) ? (int)$row['team_id'] : null;
                    $pts = isset($row['points']) ? (int)$row['points'] : 0;
                    $reason = isset($row['reason']) ? trim($row['reason']) : null;
                    if (!$teamId) continue;
                    $stmtInsert->execute([$teamId, $seasonId, $pts, $reason]);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Pontos da temporada salvos e enviados ao ranking']);
                break;

        // ========== RESETAR SPRINT (NOVO CICLO) ==========
        // ========== RESETAR TIMES (MANTER PONTOS DO RANKING) ==========
        case 'reset_teams':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $input = json_decode(file_get_contents('php://input'), true);
            $league = $input['league'] ?? null;
            
            if (!$league) {
                throw new Exception('Liga não especificada');
            }
            
            $pdo->beginTransaction();
            
            // ATENÇÃO: Isso limpa jogadores, picks, trades e histórico, mas MANTÉM os pontos do ranking!
            
            // 1. Deletar picks relacionadas aos times da liga
            $pdo->exec("
                DELETE p FROM picks p
                INNER JOIN teams t ON p.team_id = t.id
                WHERE t.league = '$league'
            ");
            
            // 2. Deletar trades relacionados aos times da liga
            $pdo->exec("
                DELETE tr FROM trades tr
                INNER JOIN teams t ON tr.from_team_id = t.id
                WHERE t.league = '$league'
            ");
            
            // 3. Deletar jogadores dos times da liga (tabela players)
            $pdo->exec("
                DELETE pl FROM players pl
                INNER JOIN teams t ON pl.team_id = t.id
                WHERE t.league = '$league'
            ");
            
            // 4. Deletar prêmios das temporadas
            $pdo->exec("
                DELETE sa FROM season_awards sa
                INNER JOIN seasons s ON sa.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 5. Deletar resultados de playoffs
            $pdo->exec("
                DELETE pr FROM playoff_results pr
                INNER JOIN seasons s ON pr.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 6. Deletar standings
            $pdo->exec("
                DELETE ss FROM season_standings ss
                INNER JOIN seasons s ON ss.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 7. Deletar draft pool
            $pdo->exec("
                DELETE dp FROM draft_pool dp
                INNER JOIN seasons s ON dp.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 8. Deletar temporadas
            $pdo->exec("DELETE FROM seasons WHERE league = '$league'");
            
            // 9. Deletar sprints
            $pdo->exec("DELETE FROM sprints WHERE league = '$league'");
            
            // 10. Deletar propostas de Free Agency da liga
            $pdo->exec("
                DELETE fao FROM free_agent_offers fao
                INNER JOIN free_agents fa ON fao.free_agent_id = fa.id
                WHERE fa.league = '$league'
            ");
            
            // 11. Deletar Free Agents da liga
            $pdo->exec("DELETE FROM free_agents WHERE league = '$league'");
            
            // 12. Resetar contadores de waivers/signings dos times
            $pdo->exec("UPDATE teams SET waivers_used = 0, fa_signings_used = 0 WHERE league = '$league'");
            
            // IMPORTANTE: NÃO deletar team_ranking_points - os pontos são mantidos!
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Times resetados com sucesso! Pontos do ranking mantidos.']);
            break;

        // ========== RESETAR SPRINT COMPLETO (DELETA TUDO) ==========
        case 'reset_sprint':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $input = json_decode(file_get_contents('php://input'), true);
            $league = $input['league'] ?? null;
            
            if (!$league) {
                throw new Exception('Liga não especificada');
            }
            
            $pdo->beginTransaction();
            
            // ATENÇÃO: Isso limpa dados operacionais da liga e também zera pontos/títulos de ranking.
            
            // 1. Deletar picks relacionadas aos times da liga
            $pdo->exec("
                DELETE p FROM picks p
                INNER JOIN teams t ON p.team_id = t.id
                WHERE t.league = '$league'
            ");
            
            // 2. Deletar trades relacionados aos times da liga
            $pdo->exec("
                DELETE tr FROM trades tr
                INNER JOIN teams t ON tr.from_team_id = t.id
                WHERE t.league = '$league'
            ");
            
            // 3. Deletar jogadores dos times da liga
            $pdo->exec("
                DELETE tp FROM team_players tp
                INNER JOIN teams t ON tp.team_id = t.id
                WHERE t.league = '$league'
            ");
            
            // 4. Deletar standings
            $pdo->exec("
                DELETE ss FROM season_standings ss
                INNER JOIN seasons s ON ss.season_id = s.id
                WHERE s.league = '$league'
            ");

            // 5. Deletar draft pool
            $pdo->exec("
                DELETE dp FROM draft_pool dp
                INNER JOIN seasons s ON dp.season_id = s.id
                WHERE s.league = '$league'
            ");

            // 6. Deletar propostas de Free Agency da liga
            $pdo->exec("
                DELETE fao FROM free_agent_offers fao
                INNER JOIN free_agents fa ON fao.free_agent_id = fa.id
                WHERE fa.league = '$league'
            ");

            // 7. Deletar Free Agents da liga
            $pdo->exec("DELETE FROM free_agents WHERE league = '$league'");

            // 8. Resetar tapas dos times
            $pdo->exec("UPDATE teams SET tapas = 0 WHERE league = '$league'");

            // 8.5 Congelar a classificacao ANTES de zerar. Sem isto, o ciclo
            // inteiro se perdia: os pontos eram apagados e nao havia como
            // saber quem tinha ganho a sprint.
            congelarRankingDaSprint($pdo, $league, 'Fim da sprint');

            // 9. Zerar ranking (pontos e tÃ­tulos) e limpar histÃ³rico detalhado da liga
            if (columnExists($pdo, 'teams', 'ranking_points')) {
                $stmtResetPoints = $pdo->prepare("UPDATE teams SET ranking_points = 0 WHERE league = ?");
                $stmtResetPoints->execute([$league]);
            }
            if (columnExists($pdo, 'teams', 'ranking_titles')) {
                $stmtResetTitles = $pdo->prepare("UPDATE teams SET ranking_titles = 0 WHERE league = ?");
                $stmtResetTitles->execute([$league]);
            }
            $stmtTable = $pdo->query("SHOW TABLES LIKE 'team_ranking_points'");
            if ($stmtTable && $stmtTable->rowCount() > 0) {
                $stmtDel = $pdo->prepare("DELETE FROM team_ranking_points WHERE league = ?");
                $stmtDel->execute([$league]);
            }
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Sprint resetado com sucesso']);
            break;

        // ========== AVANÇAR CICLO DE TRADES (ADMIN) ==========
        case 'advance_cycle':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            $league = $data['league'] ?? null;
            
            if (!$league) throw new Exception('Liga não especificada');
            
            // Incrementar ciclo de todos os times da liga
            $stmt = $pdo->prepare('UPDATE teams SET current_cycle = current_cycle + 1 WHERE league = ?');
            $stmt->execute([$league]);
            
            echo json_encode(['success' => true, 'message' => 'Ciclo de trades avançado para todos os times da liga']);
            break;

        default:
            throw new Exception('Ação inválida');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // A mensagem genérica protege o usuário, mas engolir o motivo deixava
    // qualquer falha aqui indiagnosticável. O log fica com o detalhe.
    error_log('[seasons.php:' . $action . '] ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']);
}
