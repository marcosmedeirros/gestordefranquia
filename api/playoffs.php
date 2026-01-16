<?php
/**
 * API de Playoffs
 * Gerencia brackets, partidas e pontuação
 */

session_start();
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';

header('Content-Type: application/json');

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
$pdo = db();

$method = $_SERVER['REQUEST_METHOD'];

// ========== GET ==========
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        // Buscar bracket de uma temporada
        case 'bracket':
            $seasonId = $_GET['season_id'] ?? null;
            $league = $_GET['league'] ?? null;
            
            if (!$seasonId) {
                echo json_encode(['success' => false, 'error' => 'season_id obrigatório']);
                exit;
            }
            
            $query = "
                SELECT pb.*, t.city, t.name as team_name, t.photo_url
                FROM playoff_brackets pb
                INNER JOIN teams t ON pb.team_id = t.id
                WHERE pb.season_id = ?
            ";
            $params = [$seasonId];
            
            if ($league) {
                $query .= " AND pb.league = ?";
                $params[] = $league;
            }
            
            $query .= " ORDER BY pb.conference, pb.seed";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $brackets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'bracket' => $brackets]);
            break;
            
        // Buscar partidas
        case 'matches':
            $seasonId = $_GET['season_id'] ?? null;
            $league = $_GET['league'] ?? null;
            
            if (!$seasonId) {
                echo json_encode(['success' => false, 'error' => 'season_id obrigatório']);
                exit;
            }
            
            $query = "
                SELECT pm.*
                FROM playoff_matches pm
                WHERE pm.season_id = ?
            ";
            $params = [$seasonId];
            
            if ($league) {
                $query .= " AND pm.league = ?";
                $params[] = $league;
            }
            
            $query .= " ORDER BY pm.round, pm.match_number";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'matches' => $matches]);
            break;
            
        // Buscar pontos de standings
        case 'standings_points':
            $seasonId = $_GET['season_id'] ?? null;
            
            if (!$seasonId) {
                echo json_encode(['success' => false, 'error' => 'season_id obrigatório']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT pb.team_id, pb.seed, pb.conference, pb.points_earned,
                       t.city, t.name as team_name
                FROM playoff_brackets pb
                INNER JOIN teams t ON pb.team_id = t.id
                WHERE pb.season_id = ?
                ORDER BY pb.conference, pb.seed
            ");
            $stmt->execute([$seasonId]);
            $standings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'standings' => $standings]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Ação GET inválida']);
    }
    exit;
}

// ========== POST ==========
if ($method === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? $data['action'] ?? '';
    
    switch ($action) {
        // Configurar bracket inicial com classificação
        case 'setup_bracket':
            $seasonId = $data['season_id'] ?? null;
            $league = $data['league'] ?? null;
            $standings = $data['standings'] ?? null; // { LESTE: [{team_id, seed}], OESTE: [{team_id, seed}] }
            
            if (!$seasonId || !$league || !$standings) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }
            
            try {
                $pdo->beginTransaction();
                
                // Limpar brackets e partidas anteriores
                $pdo->prepare("DELETE FROM playoff_matches WHERE season_id = ? AND league = ?")
                    ->execute([$seasonId, $league]);
                $pdo->prepare("DELETE FROM playoff_brackets WHERE season_id = ? AND league = ?")
                    ->execute([$seasonId, $league]);
                
                // Inserir classificação
                $stmtBracket = $pdo->prepare("
                    INSERT INTO playoff_brackets (season_id, league, team_id, conference, seed, status, points_earned)
                    VALUES (?, ?, ?, ?, ?, 'active', ?)
                ");
                
                foreach (['LESTE', 'OESTE'] as $conf) {
                    if (!isset($standings[$conf]) || count($standings[$conf]) !== 8) {
                        throw new Exception("Conferência {$conf} deve ter exatamente 8 times");
                    }
                    
                    foreach ($standings[$conf] as $team) {
                        // Calcular pontos de standing: 1º=4, 2-4=3, 5-8=2
                        $standingPoints = 0;
                        if ($team['seed'] == 1) {
                            $standingPoints = 4;
                        } elseif ($team['seed'] >= 2 && $team['seed'] <= 4) {
                            $standingPoints = 3;
                        } else {
                            $standingPoints = 2;
                        }
                        
                        $stmtBracket->execute([
                            $seasonId,
                            $league,
                            $team['team_id'],
                            $conf,
                            $team['seed'],
                            $standingPoints
                        ]);
                    }
                }
                
                // Criar partidas da primeira rodada para cada conferência
                // Formato: 1v8, 4v5, 3v6, 2v7
                $matchups = [
                    1 => [1, 8],
                    2 => [4, 5],
                    3 => [3, 6],
                    4 => [2, 7]
                ];
                
                $stmtMatch = $pdo->prepare("
                    INSERT INTO playoff_matches (season_id, league, conference, round, match_number, team1_id, team2_id)
                    VALUES (?, ?, ?, 'first_round', ?, ?, ?)
                ");
                
                foreach (['LESTE', 'OESTE'] as $conf) {
                    // Criar mapa seed -> team_id
                    $seedMap = [];
                    foreach ($standings[$conf] as $team) {
                        $seedMap[$team['seed']] = $team['team_id'];
                    }
                    
                    foreach ($matchups as $matchNum => $seeds) {
                        $stmtMatch->execute([
                            $seasonId,
                            $league,
                            $conf,
                            $matchNum,
                            $seedMap[$seeds[0]],
                            $seedMap[$seeds[1]]
                        ]);
                    }
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Bracket criado com sucesso!']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        // Registrar resultado de uma partida
        case 'record_result':
            $seasonId = $data['season_id'] ?? null;
            $league = $data['league'] ?? null;
            $conference = $data['conference'] ?? null;
            $round = $data['round'] ?? null;
            $matchNumber = $data['match_number'] ?? null;
            $team1Id = $data['team1_id'] ?? null;
            $team2Id = $data['team2_id'] ?? null;
            $winnerId = $data['winner_id'] ?? null;
            
            if (!$seasonId || !$league || !$conference || !$round || !$matchNumber || !$winnerId) {
                echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
                exit;
            }
            
            try {
                $pdo->beginTransaction();
                
                // Verificar se partida já existe
                $stmtCheck = $pdo->prepare("
                    SELECT id FROM playoff_matches 
                    WHERE season_id = ? AND league = ? AND conference = ? AND round = ? AND match_number = ?
                ");
                $stmtCheck->execute([$seasonId, $league, $conference, $round, $matchNumber]);
                $existing = $stmtCheck->fetch();
                
                if ($existing) {
                    // Atualizar partida existente
                    $pdo->prepare("
                        UPDATE playoff_matches 
                        SET team1_id = ?, team2_id = ?, winner_id = ?
                        WHERE id = ?
                    ")->execute([$team1Id, $team2Id, $winnerId, $existing['id']]);
                } else {
                    // Inserir nova partida
                    $pdo->prepare("
                        INSERT INTO playoff_matches (season_id, league, conference, round, match_number, team1_id, team2_id, winner_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$seasonId, $league, $conference, $round, $matchNumber, $team1Id, $team2Id, $winnerId]);
                }
                
                // Se for final de conferência, criar/atualizar jogo das finais
                if ($round === 'conference_finals') {
                    $stmtCheckFinals = $pdo->prepare("
                        SELECT id, team1_id, team2_id FROM playoff_matches 
                        WHERE season_id = ? AND league = ? AND conference = 'FINALS' AND round = 'finals' AND match_number = 1
                    ");
                    $stmtCheckFinals->execute([$seasonId, $league]);
                    $finals = $stmtCheckFinals->fetch();
                    
                    if (!$finals) {
                        // Criar partida das finais
                        if ($conference === 'LESTE') {
                            $pdo->prepare("
                                INSERT INTO playoff_matches (season_id, league, conference, round, match_number, team1_id)
                                VALUES (?, ?, 'FINALS', 'finals', 1, ?)
                            ")->execute([$seasonId, $league, $winnerId]);
                        } else {
                            $pdo->prepare("
                                INSERT INTO playoff_matches (season_id, league, conference, round, match_number, team2_id)
                                VALUES (?, ?, 'FINALS', 'finals', 1, ?)
                            ")->execute([$seasonId, $league, $winnerId]);
                        }
                    } else {
                        // Atualizar partida das finais
                        if ($conference === 'LESTE') {
                            $pdo->prepare("UPDATE playoff_matches SET team1_id = ? WHERE id = ?")
                                ->execute([$winnerId, $finals['id']]);
                        } else {
                            $pdo->prepare("UPDATE playoff_matches SET team2_id = ? WHERE id = ?")
                                ->execute([$winnerId, $finals['id']]);
                        }
                    }
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Resultado registrado!']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        // Salvar prêmios individuais
        case 'save_awards':
            $seasonId = $data['season_id'] ?? null;
            $league = $data['league'] ?? null;
            $awards = $data['awards'] ?? [];
            
            if (!$seasonId || !$league) {
                echo json_encode(['success' => false, 'error' => 'season_id e league obrigatórios']);
                exit;
            }
            
            try {
                // Verificar se tabela season_history existe
                $tableExists = $pdo->query("SHOW TABLES LIKE 'season_history'")->rowCount() > 0;
                
                if ($tableExists) {
                    // Atualizar ou inserir no histórico
                    $stmtCheck = $pdo->prepare("SELECT id FROM season_history WHERE season_id = ? AND league = ?");
                    $stmtCheck->execute([$seasonId, $league]);
                    $existing = $stmtCheck->fetch();
                    
                    if ($existing) {
                        $pdo->prepare("
                            UPDATE season_history SET
                                mvp_player = ?, mvp_team_id = ?,
                                dpoy_player = ?, dpoy_team_id = ?,
                                mip_player = ?, mip_team_id = ?,
                                sixth_man_player = ?, sixth_man_team_id = ?
                            WHERE id = ?
                        ")->execute([
                            $awards['mvp_player'], $awards['mvp_team_id'] ?: null,
                            $awards['dpoy_player'], $awards['dpoy_team_id'] ?: null,
                            $awards['mip_player'], $awards['mip_team_id'] ?: null,
                            $awards['sixth_man_player'], $awards['sixth_man_team_id'] ?: null,
                            $existing['id']
                        ]);
                    } else {
                        $pdo->prepare("
                            INSERT INTO season_history (season_id, league, mvp_player, mvp_team_id, dpoy_player, dpoy_team_id, mip_player, mip_team_id, sixth_man_player, sixth_man_team_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ")->execute([
                            $seasonId, $league,
                            $awards['mvp_player'], $awards['mvp_team_id'] ?: null,
                            $awards['dpoy_player'], $awards['dpoy_team_id'] ?: null,
                            $awards['mip_player'], $awards['mip_team_id'] ?: null,
                            $awards['sixth_man_player'], $awards['sixth_man_team_id'] ?: null
                        ]);
                    }
                }
                
                echo json_encode(['success' => true, 'message' => 'Prêmios salvos!']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        // Finalizar playoffs e calcular pontos
        case 'finalize':
            $seasonId = $data['season_id'] ?? null;
            $league = $data['league'] ?? null;
            
            if (!$seasonId || !$league) {
                echo json_encode(['success' => false, 'error' => 'season_id e league obrigatórios']);
                exit;
            }
            
            try {
                $pdo->beginTransaction();
                
                // Buscar todos os times da liga
                $stmtTeams = $pdo->prepare("SELECT id FROM teams WHERE league = ?");
                $stmtTeams->execute([$league]);
                $allTeams = $stmtTeams->fetchAll(PDO::FETCH_COLUMN);
                
                // Inicializar pontos
                $teamPoints = [];
                foreach ($allTeams as $tid) {
                    $teamPoints[$tid] = 0;
                }
                
                // 1. Pontos de classificação (standing)
                $stmtBrackets = $pdo->prepare("
                    SELECT team_id, seed, points_earned FROM playoff_brackets 
                    WHERE season_id = ? AND league = ?
                ");
                $stmtBrackets->execute([$seasonId, $league]);
                $brackets = $stmtBrackets->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($brackets as $b) {
                    $teamPoints[$b['team_id']] += $b['points_earned'];
                }
                
                // 2. Pontos de playoffs
                // Buscar final
                $stmtFinal = $pdo->prepare("
                    SELECT * FROM playoff_matches 
                    WHERE season_id = ? AND league = ? AND round = 'finals' AND match_number = 1
                ");
                $stmtFinal->execute([$seasonId, $league]);
                $final = $stmtFinal->fetch(PDO::FETCH_ASSOC);
                
                $champion = null;
                $runnerUp = null;
                $conferenceFinalists = [];
                $semifinalists = [];
                $firstRoundLosers = [];
                
                if ($final && $final['winner_id']) {
                    $champion = $final['winner_id'];
                    $runnerUp = ($final['winner_id'] == $final['team1_id']) ? $final['team2_id'] : $final['team1_id'];
                    
                    // Campeão: +5
                    $teamPoints[$champion] += 5;
                    // Vice: +2
                    if ($runnerUp) {
                        $teamPoints[$runnerUp] += 2;
                    }
                }
                
                // Finais de conferência (perdedores = +3)
                $stmtConfFinals = $pdo->prepare("
                    SELECT * FROM playoff_matches 
                    WHERE season_id = ? AND league = ? AND round = 'conference_finals' AND winner_id IS NOT NULL
                ");
                $stmtConfFinals->execute([$seasonId, $league]);
                $confFinals = $stmtConfFinals->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($confFinals as $g) {
                    $loserId = ($g['winner_id'] == $g['team1_id']) ? $g['team2_id'] : $g['team1_id'];
                    if ($loserId && $loserId != $champion && $loserId != $runnerUp) {
                        $conferenceFinalists[] = $loserId;
                        $teamPoints[$loserId] += 3;
                    }
                }
                
                // Semifinais (perdedores = +2)
                $stmtSemis = $pdo->prepare("
                    SELECT * FROM playoff_matches 
                    WHERE season_id = ? AND league = ? AND round = 'semifinals' AND winner_id IS NOT NULL
                ");
                $stmtSemis->execute([$seasonId, $league]);
                $semis = $stmtSemis->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($semis as $g) {
                    $loserId = ($g['winner_id'] == $g['team1_id']) ? $g['team2_id'] : $g['team1_id'];
                    if ($loserId && !in_array($loserId, [$champion, $runnerUp]) && !in_array($loserId, $conferenceFinalists)) {
                        $semifinalists[] = $loserId;
                        $teamPoints[$loserId] += 2;
                    }
                }
                
                // Primeira rodada (perdedores = +1)
                $stmtFirst = $pdo->prepare("
                    SELECT * FROM playoff_matches 
                    WHERE season_id = ? AND league = ? AND round = 'first_round' AND winner_id IS NOT NULL
                ");
                $stmtFirst->execute([$seasonId, $league]);
                $firstRound = $stmtFirst->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($firstRound as $g) {
                    $loserId = ($g['winner_id'] == $g['team1_id']) ? $g['team2_id'] : $g['team1_id'];
                    if ($loserId && 
                        !in_array($loserId, [$champion, $runnerUp]) && 
                        !in_array($loserId, $conferenceFinalists) &&
                        !in_array($loserId, $semifinalists)) {
                        $firstRoundLosers[] = $loserId;
                        $teamPoints[$loserId] += 1;
                    }
                }
                
                // 3. Pontos de prêmios individuais (+1 cada)
                $tableExists = $pdo->query("SHOW TABLES LIKE 'season_history'")->rowCount() > 0;
                if ($tableExists) {
                    $stmtAwards = $pdo->prepare("
                        SELECT mvp_team_id, dpoy_team_id, mip_team_id, sixth_man_team_id
                        FROM season_history WHERE season_id = ? AND league = ?
                    ");
                    $stmtAwards->execute([$seasonId, $league]);
                    $awards = $stmtAwards->fetch(PDO::FETCH_ASSOC);
                    
                    if ($awards) {
                        if ($awards['mvp_team_id']) $teamPoints[$awards['mvp_team_id']] += 1;
                        if ($awards['dpoy_team_id']) $teamPoints[$awards['dpoy_team_id']] += 1;
                        if ($awards['mip_team_id']) $teamPoints[$awards['mip_team_id']] += 1;
                        if ($awards['sixth_man_team_id']) $teamPoints[$awards['sixth_man_team_id']] += 1;
                    }
                    
                    // Atualizar campeão/vice no histórico
                    if ($champion || $runnerUp) {
                        $pdo->prepare("
                            UPDATE season_history SET champion_team_id = ?, runner_up_team_id = ?
                            WHERE season_id = ? AND league = ?
                        ")->execute([$champion, $runnerUp, $seasonId, $league]);
                    }
                }
                
                // 4. Aplicar pontos ao ranking geral
                foreach ($teamPoints as $teamId => $points) {
                    if ($points > 0) {
                        // Verificar se tabela team_ranking_points existe
                        $tableExists = $pdo->query("SHOW TABLES LIKE 'team_ranking_points'")->rowCount() > 0;
                        
                        if ($tableExists) {
                            $pdo->prepare("
                                INSERT INTO team_ranking_points (team_id, points)
                                VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE points = points + VALUES(points)
                            ")->execute([$teamId, $points]);
                        }
                    }
                }
                
                // 5. Atualizar status dos brackets
                if ($champion) {
                    $pdo->prepare("UPDATE playoff_brackets SET status = 'champion' WHERE season_id = ? AND league = ? AND team_id = ?")
                        ->execute([$seasonId, $league, $champion]);
                }
                if ($runnerUp) {
                    $pdo->prepare("UPDATE playoff_brackets SET status = 'runner_up' WHERE season_id = ? AND league = ? AND team_id = ?")
                        ->execute([$seasonId, $league, $runnerUp]);
                }
                foreach ($conferenceFinalists as $tid) {
                    $pdo->prepare("UPDATE playoff_brackets SET status = 'conference_finalist' WHERE season_id = ? AND league = ? AND team_id = ?")
                        ->execute([$seasonId, $league, $tid]);
                }
                foreach ($semifinalists as $tid) {
                    $pdo->prepare("UPDATE playoff_brackets SET status = 'semifinalist' WHERE season_id = ? AND league = ? AND team_id = ?")
                        ->execute([$seasonId, $league, $tid]);
                }
                foreach ($firstRoundLosers as $tid) {
                    $pdo->prepare("UPDATE playoff_brackets SET status = 'first_round' WHERE season_id = ? AND league = ? AND team_id = ?")
                        ->execute([$seasonId, $league, $tid]);
                }
                
                $pdo->commit();
                echo json_encode([
                    'success' => true, 
                    'message' => 'Playoffs finalizados! Pontos aplicados.',
                    'champion' => $champion,
                    'runner_up' => $runnerUp,
                    'points' => $teamPoints
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Ação POST inválida: ' . $action]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);
