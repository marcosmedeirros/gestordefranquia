<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$pdo = db();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ==================== ADMIN ONLY ACTIONS ====================
$adminActions = ['create_season', 'end_season', 'start_draft', 'end_draft', 'add_draft_player', 
                 'update_draft_player', 'delete_draft_player', 'assign_draft_pick', 
                 'set_standings', 'set_playoff_results', 'set_awards', 'reset_teams', 'reset_sprint'];

if (in_array($action, $adminActions) && ($user['user_type'] ?? 'jogador') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
    exit;
}

try {
    switch ($action) {
        // ========== BUSCAR TEMPORADA ATUAL ==========
        case 'current_season':
            $league = $_GET['league'] ?? null;
            if (!$league) {
                throw new Exception('Liga não especificada');
            }
            
            $stmt = $pdo->prepare("
                SELECT s.*, sp.sprint_number, sp.status as sprint_status,
                       lsc.max_seasons as sprint_max_seasons
                FROM seasons s
                INNER JOIN sprints sp ON s.sprint_id = sp.id
                INNER JOIN league_sprint_config lsc ON s.league = lsc.league
                WHERE s.league = ? AND s.status != 'completed'
                ORDER BY s.id DESC LIMIT 1
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
                SELECT s.*, sp.sprint_number,
                       (SELECT COUNT(*) FROM draft_pool WHERE season_id = s.id) as draft_players_count
                FROM seasons s
                INNER JOIN sprints sp ON s.sprint_id = sp.id
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
            $league = $data['league'] ?? null;
            
            if (!$league || !in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'])) {
                throw new Exception('Liga inválida');
            }
            
            $pdo->beginTransaction();
            
            // Buscar ou criar sprint atual
            $stmtSprint = $pdo->prepare("
                SELECT id, sprint_number FROM sprints 
                WHERE league = ? AND status = 'active' 
                ORDER BY id DESC LIMIT 1
            ");
            $stmtSprint->execute([$league]);
            $sprint = $stmtSprint->fetch();
            
            if (!$sprint) {
                // Criar primeiro sprint
                $stmtCreate = $pdo->prepare("
                    INSERT INTO sprints (league, sprint_number, start_date) 
                    VALUES (?, 1, CURDATE())
                ");
                $stmtCreate->execute([$league]);
                $sprintId = $pdo->lastInsertId();
                $sprintNumber = 1;
            } else {
                $sprintId = $sprint['id'];
                $sprintNumber = $sprint['sprint_number'];
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
            $year = date('Y');
            
            $stmtSeason = $pdo->prepare("
                INSERT INTO seasons (sprint_id, league, season_number, year, start_date, status, current_phase)
                VALUES (?, ?, ?, ?, CURDATE(), 'draft', 'draft')
            ");
            $stmtSeason->execute([$sprintId, $league, $seasonNumber, $year]);
            $seasonId = $pdo->lastInsertId();
            
            // Gerar picks automaticamente para os próximos 5 anos
            if ($seasonNumber === 1) {
                $stmtTeams = $pdo->prepare("SELECT id FROM teams WHERE league = ?");
                $stmtTeams->execute([$league]);
                $teams = $stmtTeams->fetchAll();
                
                $stmtPick = $pdo->prepare("
                    INSERT INTO picks (team_id, original_team_id, season_year, round, season_id, auto_generated)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                
                // Gerar picks apenas para os próximos 5 anos
                $yearsToGenerate = 5;
                foreach ($teams as $team) {
                    for ($tempNum = 1; $tempNum <= $yearsToGenerate; $tempNum++) {
                        $yearLabel = "Ano " . str_pad($tempNum, 2, '0', STR_PAD_LEFT); // "Ano 01", "Ano 02", etc
                        
                        // Pick Rodada 1 para temporada X
                        $pickLabel = "T{$tempNum} R1";
                        $stmtPick->execute([$team['id'], $team['id'], $yearLabel, $pickLabel, $seasonId]);
                        
                        // Pick Rodada 2 para temporada X
                        $pickLabel = "T{$tempNum} R2";
                        $stmtPick->execute([$team['id'], $team['id'], $yearLabel, $pickLabel, $seasonId]);
                    }
                }
                
                $totalPicksPerTeam = $yearsToGenerate * 2; // 2 picks por ano
            } else {
                // Verificar se precisa gerar mais picks (quando chegou no ano 6, 11, 16, etc)
                $stmtMaxYear = $pdo->prepare("
                    SELECT MAX(CAST(SUBSTRING(season_year, 5) AS UNSIGNED)) as max_year 
                    FROM picks 
                    WHERE team_id IN (SELECT id FROM teams WHERE league = ?)
                ");
                $stmtMaxYear->execute([$league]);
                $maxYear = (int)$stmtMaxYear->fetchColumn();
                
                // Se o ano atual for >= maxYear - 1, gerar mais 5 anos
                if ($seasonNumber >= $maxYear - 1) {
                    $stmtTeams = $pdo->prepare("SELECT id FROM teams WHERE league = ?");
                    $stmtTeams->execute([$league]);
                    $teams = $stmtTeams->fetchAll();
                    
                    $stmtPick = $pdo->prepare("
                        INSERT INTO picks (team_id, original_team_id, season_year, round, season_id, auto_generated)
                        VALUES (?, ?, ?, ?, ?, 1)
                    ");
                    
                    $startYear = $maxYear + 1;
                    $endYear = $maxYear + 5;
                    
                    foreach ($teams as $team) {
                        for ($tempNum = $startYear; $tempNum <= $endYear; $tempNum++) {
                            $yearLabel = "Ano " . str_pad($tempNum, 2, '0', STR_PAD_LEFT);
                            
                            $pickLabel = "T{$tempNum} R1";
                            $stmtPick->execute([$team['id'], $team['id'], $yearLabel, $pickLabel, $seasonId]);
                            
                            $pickLabel = "T{$tempNum} R2";
                            $stmtPick->execute([$team['id'], $team['id'], $yearLabel, $pickLabel, $seasonId]);
                        }
                    }
                }
                
                $totalPicksPerTeam = 0; // Picks já foram gerenciadas
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'season_id' => $seasonId,
                'message' => "Temporada {$seasonNumber} criada! Geradas {$totalPicksPerTeam} picks por time ({$maxSeasons} temporadas x 2 rodadas)."
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
            
            $stmt = $pdo->prepare("
                INSERT INTO draft_pool (season_id, name, position, age, ovr, photo_url, bio, strengths, weaknesses)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['season_id'],
                $data['name'],
                $data['position'],
                $data['age'],
                $data['ovr'],
                $data['photo_url'] ?? null,
                $data['bio'] ?? null,
                $data['strengths'] ?? null,
                $data['weaknesses'] ?? null
            ]);
            
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        // ========== ATUALIZAR JOGADOR DO DRAFT (ADMIN) ==========
        case 'update_draft_player':
            if ($method !== 'PUT') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("
                UPDATE draft_pool 
                SET name = ?, position = ?, age = ?, ovr = ?, 
                    photo_url = ?, bio = ?, strengths = ?, weaknesses = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['name'],
                $data['position'],
                $data['age'],
                $data['ovr'],
                $data['photo_url'] ?? null,
                $data['bio'] ?? null,
                $data['strengths'] ?? null,
                $data['weaknesses'] ?? null,
                $data['id']
            ]);
            
            echo json_encode(['success' => true]);
            break;

        // ========== DELETAR JOGADOR DO DRAFT (ADMIN) ==========
        case 'delete_draft_player':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['player_id'] ?? null;
            if (!$id) throw new Exception('ID não especificado');
            
            $stmt = $pdo->prepare("DELETE FROM draft_pool WHERE id = ? AND draft_status = 'available'");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true]);
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
            $firstRound = isset($input['first_round_losses']) && is_array($input['first_round_losses']) ? array_map('intval', array_filter($input['first_round_losses'])) : [];
            $secondRound = isset($input['second_round_losses']) && is_array($input['second_round_losses']) ? array_map('intval', array_filter($input['second_round_losses'])) : [];
            $confFinal = isset($input['conference_final_losses']) && is_array($input['conference_final_losses']) ? array_map('intval', array_filter($input['conference_final_losses'])) : [];
            
            if (!$seasonId || !$champion || !$runnerUp) {
                throw new Exception('Dados incompletos: season_id, champion e runner_up são obrigatórios');
            }
            
            if ($champion == $runnerUp) {
                throw new Exception('Campeão e vice-campeão não podem ser o mesmo time');
            }

            $allEliminated = array_merge($firstRound, $secondRound, $confFinal);
            if (count(array_unique($allEliminated)) !== count($allEliminated)) {
                throw new Exception('Um time não pode ser marcado em mais de uma fase eliminada');
            }
            if (in_array($champion, $allEliminated) || in_array($runnerUp, $allEliminated)) {
                throw new Exception('Não inclua campeão ou vice nas fases de eliminados');
            }
            
            $pdo->beginTransaction();
            
            // Salvar resultados dos playoffs
            $stmtDelete = $pdo->prepare("DELETE FROM playoff_results WHERE season_id = ?");
            $stmtDelete->execute([$seasonId]);
            
            $stmtPlayoff = $pdo->prepare("
                INSERT INTO playoff_results (season_id, team_id, position)
                VALUES (?, ?, ?)
            ");
            $stmtPlayoff->execute([$seasonId, $champion, 'champion']);
            $stmtPlayoff->execute([$seasonId, $runnerUp, 'runner_up']);
            foreach ($firstRound as $teamId) {
                $stmtPlayoff->execute([$seasonId, $teamId, 'first_round']);
            }
            foreach ($secondRound as $teamId) {
                $stmtPlayoff->execute([$seasonId, $teamId, 'second_round']);
            }
            foreach ($confFinal as $teamId) {
                $stmtPlayoff->execute([$seasonId, $teamId, 'conference_final']);
            }
            
            // Salvar prêmios individuais
            $stmtDeleteAwards = $pdo->prepare("DELETE FROM season_awards WHERE season_id = ?");
            $stmtDeleteAwards->execute([$seasonId]);
            
            $stmtAward = $pdo->prepare("
                INSERT INTO season_awards (season_id, team_id, award_type, player_name)
                VALUES (?, ?, ?, ?)
            ");
            
            if (!empty($input['mvp']) && !empty($input['mvp_team_id'])) {
                $stmtAward->execute([$seasonId, (int)$input['mvp_team_id'], 'MVP', $input['mvp']]);
            }
            if (!empty($input['dpoy']) && !empty($input['dpoy_team_id'])) {
                $stmtAward->execute([$seasonId, (int)$input['dpoy_team_id'], 'DPOY', $input['dpoy']]);
            }
            if (!empty($input['mip']) && !empty($input['mip_team_id'])) {
                $stmtAward->execute([$seasonId, (int)$input['mip_team_id'], 'MIP', $input['mip']]);
            }
            if (!empty($input['sixth_man']) && !empty($input['sixth_man_team_id'])) {
                $stmtAward->execute([$seasonId, (int)$input['sixth_man_team_id'], '6th Man', $input['sixth_man']]);
            }
            
            // Atualizar pontos no ranking com nova pontuação por fase
            $stmtGetSeason = $pdo->prepare("SELECT league, year FROM seasons WHERE id = ?");
            $stmtGetSeason->execute([$seasonId]);
            $season = $stmtGetSeason->fetch();
            
            if ($season) {
                // Resetar pontos da temporada antes de aplicar nova lógica
                $stmtDeletePoints = $pdo->prepare("DELETE FROM team_ranking_points WHERE season_id = ?");
                $stmtDeletePoints->execute([$seasonId]);

                $stmtPoints = $pdo->prepare("
                    INSERT INTO team_ranking_points (team_id, season_id, points, reason)
                    VALUES (?, ?, ?, ?)
                ");

                $addPoints = function($teamId, $points, $reason) use ($stmtPoints, $seasonId) {
                    if (!$teamId) return;
                    $stmtPoints->execute([$teamId, $seasonId, $points, $reason]);
                };

                // Campeão e vice
                $addPoints($champion, 11, 'Campeão');
                $addPoints($runnerUp, 8, 'Vice-Campeão');

                // Eliminados por fase
                foreach ($confFinal as $teamId) {
                    $addPoints($teamId, 6, 'Final de Conferência');
                }
                foreach ($secondRound as $teamId) {
                    $addPoints($teamId, 3, '2ª Rodada');
                }
                foreach ($firstRound as $teamId) {
                    $addPoints($teamId, 1, '1ª Rodada');
                }

                // Prêmios: 1 ponto cada
                foreach (['mvp', 'dpoy', 'mip', 'sixth_man'] as $award) {
                    $teamIdKey = $award . '_team_id';
                    if (!empty($input[$teamIdKey])) {
                        $addPoints((int)$input[$teamIdKey], 1, 'Prêmio ' . strtoupper($award));
                    }
                }
            }
            
            // Marcar temporada como completa
            $stmtComplete = $pdo->prepare("UPDATE seasons SET status = 'completed' WHERE id = ?");
            $stmtComplete->execute([$seasonId]);
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Histórico salvo com sucesso']);
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
            
            // ATENÇÃO: Isso deleta TUDO da liga!
            
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
            
            // 4. Deletar pontos de ranking dos times
            $pdo->exec("
                DELETE trp FROM team_ranking_points trp
                INNER JOIN teams t ON trp.team_id = t.id
                WHERE t.league = '$league'
            ");
            
            // 5. Deletar prêmios das temporadas
            $pdo->exec("
                DELETE sa FROM season_awards sa
                INNER JOIN seasons s ON sa.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 6. Deletar resultados de playoffs
            $pdo->exec("
                DELETE pr FROM playoff_results pr
                INNER JOIN seasons s ON pr.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 7. Deletar standings
            $pdo->exec("
                DELETE ss FROM season_standings ss
                INNER JOIN seasons s ON ss.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 8. Deletar draft pool
            $pdo->exec("
                DELETE dp FROM draft_pool dp
                INNER JOIN seasons s ON dp.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 9. Deletar temporadas
            $pdo->exec("DELETE FROM seasons WHERE league = '$league'");
            
            // 10. Deletar sprints
            $pdo->exec("DELETE FROM sprints WHERE league = '$league'");
            
            // 11. Deletar times (e seus usuários associados)
            $pdo->exec("
                DELETE u FROM users u
                INNER JOIN teams t ON u.id = t.user_id
                WHERE t.league = '$league'
            ");
            
            $pdo->exec("DELETE FROM teams WHERE league = '$league'");
            
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
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

