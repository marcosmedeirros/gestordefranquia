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

// Libera o lock da sessão imediatamente após ler os dados do usuário.
// Isso evita bloqueios quando múltiplas requisições são feitas em paralelo
// (ex.: salvar histórico e, em seguida, carregar o histórico na aba).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
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
                
                // Gerar picks para as próximas 5 temporadas (numéricas para respeitar o schema)
                $yearsToGenerate = 5; // temporadas futuras
                foreach ($teams as $team) {
                    for ($tempNum = 1; $tempNum <= $yearsToGenerate; $tempNum++) {
                        // season_year numérico (1,2,3,4,5...)
                        $seasonYearNumeric = $tempNum;
                        // round numérico (1 ou 2)
                        $stmtPick->execute([$team['id'], $team['id'], $seasonYearNumeric, 1, $seasonId]);
                        $stmtPick->execute([$team['id'], $team['id'], $seasonYearNumeric, 2, $seasonId]);
                    }
                }
                
                $totalPicksPerTeam = $yearsToGenerate * 2; // 2 picks por temporada
            } else {
                // Verificar se precisa gerar mais picks (quando chegou no ano 6, 11, 16, etc)
                $stmtMaxYear = $pdo->prepare("
                    SELECT MAX(season_year) as max_year 
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
                            $seasonYearNumeric = $tempNum;
                            $stmtPick->execute([$team['id'], $team['id'], $seasonYearNumeric, 1, $seasonId]);
                            $stmtPick->execute([$team['id'], $team['id'], $seasonYearNumeric, 2, $seasonId]);
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
                INSERT INTO draft_pool (season_id, name, position, secondary_position, age, ovr, photo_url, bio, strengths, weaknesses)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
                $stmt->execute([
                    $data['season_id'],
                    $data['name'],
                    $data['position'],
                    $data['secondary_position'] ?? null,
                    $data['age'],
                    $data['ovr'],
                    $data['photo_url'] ?? null,
                    $data['bio'] ?? null,
                    $data['strengths'] ?? null,
            $data['weaknesses'] ?? null
        ]);
            
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
            
            $pdo->beginTransaction();
            
            // 1. Buscar informações da Liga (necessário para a tabela team_ranking_points)
            $stmtSeason = $pdo->prepare("SELECT league FROM seasons WHERE id = ?");
            $stmtSeason->execute([$seasonId]);
            $seasonData = $stmtSeason->fetch();
            
            if (!$seasonData) throw new Exception('Temporada não encontrada');
            $league = $seasonData['league'];

            // 2. Salvar Tabelas Auxiliares (Playoff Results e Awards)
            // (Mantemos essa parte pois ela alimenta a exibição visual do histórico)
            $pdo->prepare("DELETE FROM playoff_results WHERE season_id = ?")->execute([$seasonId]);
            $pdo->prepare("DELETE FROM season_awards WHERE season_id = ?")->execute([$seasonId]);
            
            $stmtPlayoff = $pdo->prepare("INSERT INTO playoff_results (season_id, team_id, position) VALUES (?, ?, ?)");
            $stmtPlayoff->execute([$seasonId, $champion, 'champion']);
            $stmtPlayoff->execute([$seasonId, $runnerUp, 'runner_up']);
            foreach ($firstRound as $tid) $stmtPlayoff->execute([$seasonId, $tid, 'first_round']);
            foreach ($secondRound as $tid) $stmtPlayoff->execute([$seasonId, $tid, 'second_round']);
            foreach ($confFinal as $tid) $stmtPlayoff->execute([$seasonId, $tid, 'conference_final']);
            
            // Inserir prêmios na tabela auxiliar
            $stmtAward = $pdo->prepare("INSERT INTO season_awards (season_id, team_id, award_type, player_name) VALUES (?, ?, ?, ?)");
            $awardTypes = ['mvp', 'dpoy', 'mip', 'sixth_man'];
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

            // Processar Campeão (11 pontos segundo seu código anterior)
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
            
            // Marcar temporada como completa
            $pdo->prepare("UPDATE seasons SET status = 'completed' WHERE id = ?")->execute([$seasonId]);
            
            $pdo->commit();
            
                echo json_encode(['success' => true, 'message' => 'Histórico salvo!']);
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
                DELETE fap FROM free_agent_proposals fap
                INNER JOIN free_agents fa ON fap.free_agent_id = fa.id
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
            
            // 11. Deletar propostas de Free Agency da liga
            $pdo->exec("
                DELETE fap FROM free_agent_proposals fap
                INNER JOIN free_agents fa ON fap.free_agent_id = fa.id
                WHERE fa.league = '$league'
            ");
            
            // 12. Deletar Free Agents da liga
            $pdo->exec("DELETE FROM free_agents WHERE league = '$league'");
            
            // 13. Deletar times (e seus usuários associados)
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

