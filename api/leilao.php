<?php
/**
 * API do Leilao de Jogadores
 * Sistema de trocas via leilao
 */

session_start();
require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Nao autorizado']);
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? (($_SESSION['user_type'] ?? '') === 'admin');
$team_id = $_SESSION['team_id'] ?? null;
$league_id = $_SESSION['current_league_id'] ?? null;

$pdo = db();
ensureTempPlayerColumns($pdo);
ensureAuctionTableCompat($pdo);
ensureProposalPicksTable($pdo);

function teamColumnExists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM teams LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

if (!$team_id) {
    $select = ['id'];
    $hasLeagueId = teamColumnExists($pdo, 'league_id');
    $hasLeagueName = teamColumnExists($pdo, 'league');
    if ($hasLeagueId) {
        $select[] = 'league_id';
    }
    if ($hasLeagueName) {
        $select[] = 'league';
    }
    $stmt = $pdo->prepare("SELECT " . implode(', ', $select) . " FROM teams WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $teamRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($teamRow) {
        $team_id = (int) $teamRow['id'];
        if (!$league_id) {
            if ($hasLeagueId && !empty($teamRow['league_id'])) {
                $league_id = (int) $teamRow['league_id'];
            } elseif ($hasLeagueName && !empty($teamRow['league'])) {
                $stmt = $pdo->prepare("SELECT id FROM leagues WHERE name = ? LIMIT 1");
                $stmt->execute([$teamRow['league']]);
                $leagueRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($leagueRow && !empty($leagueRow['id'])) {
                    $league_id = (int) $leagueRow['id'];
                }
            }
        }
    }
}

function playerOvrColumn(PDO $pdo): string
{
    $stmt = $pdo->query("SHOW COLUMNS FROM players LIKE 'ovr'");
    return $stmt && $stmt->rowCount() > 0 ? 'ovr' : 'overall';
}

function ensureTempPlayerColumns(PDO $pdo): void
{
    try {
        $pdo->exec("
            ALTER TABLE leilao_jogadores 
            ADD COLUMN IF NOT EXISTS temp_name VARCHAR(120) NULL,
            ADD COLUMN IF NOT EXISTS temp_position VARCHAR(10) NULL,
            ADD COLUMN IF NOT EXISTS temp_age INT NULL,
            ADD COLUMN IF NOT EXISTS temp_ovr INT NULL,
            ADD COLUMN IF NOT EXISTS is_temp_player TINYINT(1) DEFAULT 0
        ");
    } catch (Throwable $e) {
        // Ignorar falhas de ALTER para compatibilidade
    }
}

function ensureAuctionTableCompat(PDO $pdo): void
{
    try {
        // Permitir player_id e team_id nulos para jogadores criados sem time
        $pdo->exec("ALTER TABLE leilao_jogadores MODIFY COLUMN player_id INT NULL");
    } catch (Throwable $e) { /* ignore */ }
    try {
        $pdo->exec("ALTER TABLE leilao_jogadores MODIFY COLUMN team_id INT NULL");
    } catch (Throwable $e) { /* ignore */ }
    try {
        // Incluir status 'pendente' e definir default como 'pendente'
        $pdo->exec("ALTER TABLE leilao_jogadores MODIFY COLUMN status ENUM('pendente','ativo','finalizado','cancelado') DEFAULT 'pendente'");
    } catch (Throwable $e) { /* ignore */ }
}

function ensureProposalPicksTable(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS leilao_proposta_picks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            proposta_id INT NOT NULL,
            pick_id INT NOT NULL,
            INDEX idx_proposta_pick (proposta_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ignore */ }
}

function criarJogadorParaLeilao(PDO $pdo, array $new_player, int $user_id, ?int $league_id): array
{
    $name = trim((string)($new_player['name'] ?? ''));
    $position = trim((string)($new_player['position'] ?? ''));
    $age = (int)($new_player['age'] ?? 0);
    $ovr = (int)($new_player['ovr'] ?? 0);

    if (!$name || !$position || !$age || !$ovr) {
        throw new InvalidArgumentException('Dados do novo jogador incompletos');
    }

    return [
        'player_id' => null,
        'team_id' => null,
        'name' => $name,
        'position' => $position,
        'age' => $age,
        'ovr' => $ovr
    ];
}

// GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'listar_ativos':
            listarLeiloesAtivos($pdo, $league_id);
            break;
        case 'listar_admin':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            listarLeiloesAdmin($pdo);
            break;
        case 'listar_temp':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            listarLeiloesTemporarios($pdo);
            break;
        case 'minhas_propostas':
            minhasPropostas($pdo, $team_id);
            break;
        case 'propostas_recebidas':
            propostasRecebidas($pdo, $team_id);
            break;
        case 'ver_propostas':
            $leilao_id = $_GET['leilao_id'] ?? 0;
            verPropostas($pdo, $leilao_id, $team_id, $is_admin);
            break;
        case 'ver_propostas_enviadas':
            $leilao_id = $_GET['leilao_id'] ?? 0;
            verPropostasEnviadas($pdo, $leilao_id, $team_id);
            break;
        case 'historico':
            $league_id_param = $_GET['league_id'] ?? null;
            historicoLeiloes($pdo, $league_id_param);
            break;
        case 'minhas_picks':
            minhasPicks($pdo, $team_id);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Acao nao reconhecida']);
    }
    exit;
}

// POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? '';
    
    switch ($action) {
        case 'cadastrar':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            cadastrarLeilao($pdo, $body, $user_id);
            break;
        case 'iniciar_leilao':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            iniciarLeilao($pdo, $body);
            break;
        case 'remover_temp':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            removerTempLeilao($pdo, $body);
            break;
        case 'criar_jogador':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            try {
                $created = criarJogadorParaLeilao($pdo, $body['new_player'] ?? [], $user_id, $body['league_id'] ?? null);
                echo json_encode(['success' => true] + $created);
            } catch (Throwable $e) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
        case 'cancelar':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            cancelarLeilao($pdo, $body);
            break;
        case 'enviar_proposta':
            enviarProposta($pdo, $body, $team_id);
            break;
        case 'aceitar_proposta':
            aceitarProposta($pdo, $body, $team_id, $is_admin);
            break;
        case 'recusar_proposta':
            recusarProposta($pdo, $body, $team_id, $is_admin);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Acao nao reconhecida']);
    }
    exit;
}

// ========== FUNCOES GET ==========

function listarLeiloesAtivos($pdo, $league_id) {
    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.*, 
                   COALESCE(l.temp_name, p.name) as player_name, 
                   COALESCE(l.temp_position, p.position) as position, 
                   COALESCE(l.temp_age, p.age) as age, 
                   COALESCE(l.temp_ovr, p.{$ovrColumn}) as ovr,
                   t.name as team_name,
                   lg.name as league_name,
                   (SELECT COUNT(*) FROM leilao_propostas WHERE leilao_id = l.id) as total_propostas
            FROM leilao_jogadores l
            LEFT JOIN players p ON l.player_id = p.id
            LEFT JOIN teams t ON l.team_id = t.id
            LEFT JOIN leagues lg ON l.league_id = lg.id
            WHERE l.status = 'ativo' AND (l.data_fim IS NULL OR l.data_fim > NOW())";
    
    if ($league_id) {
        $sql .= " AND l.league_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$league_id]);
    } else {
        $stmt = $pdo->query($sql);
    }
    
    $leiloes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'leiloes' => $leiloes]);
}

function listarLeiloesAdmin($pdo) {
    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.*, 
                   COALESCE(l.temp_name, p.name) as player_name, 
                   COALESCE(l.temp_position, p.position) as position, 
                   COALESCE(l.temp_age, p.age) as age, 
                   COALESCE(l.temp_ovr, p.{$ovrColumn}) as ovr,
                   t.name as team_name,
                   lg.name as league_name,
                   (SELECT COUNT(*) FROM leilao_propostas WHERE leilao_id = l.id) as total_propostas
            FROM leilao_jogadores l
            LEFT JOIN players p ON l.player_id = p.id
            LEFT JOIN teams t ON l.team_id = t.id
            LEFT JOIN leagues lg ON l.league_id = lg.id
            ORDER BY l.created_at DESC";
    
    $stmt = $pdo->query($sql);
    $leiloes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'leiloes' => $leiloes]);
}

function listarLeiloesTemporarios($pdo) {
    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.*, 
                   COALESCE(l.temp_name, p.name) as player_name, 
                   COALESCE(l.temp_position, p.position) as position, 
                   COALESCE(l.temp_age, p.age) as age, 
                   COALESCE(l.temp_ovr, p.{$ovrColumn}) as ovr,
                   t.name as team_name,
                   lg.name as league_name,
                   (SELECT COUNT(*) FROM leilao_propostas WHERE leilao_id = l.id) as total_propostas
            FROM leilao_jogadores l
            LEFT JOIN players p ON l.player_id = p.id
            LEFT JOIN teams t ON l.team_id = t.id
        LEFT JOIN leagues lg ON l.league_id = lg.id
            WHERE (l.is_temp_player = 1 OR l.temp_name IS NOT NULL)
            ORDER BY l.created_at DESC";
    $stmt = $pdo->query($sql);
    $leiloes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'leiloes' => $leiloes]);
}

function minhasPropostas($pdo, $team_id) {
    if (!$team_id) {
        echo json_encode(['success' => true, 'propostas' => []]);
        return;
    }
    
    $sql = "SELECT lp.*, 
                   l.player_id,
                   COALESCE(l.temp_name, p.name) as player_name,
                   t.name as team_name,
                   GROUP_CONCAT(po.name SEPARATOR ', ') as jogadores_oferecidos
            FROM leilao_propostas lp
            JOIN leilao_jogadores l ON lp.leilao_id = l.id
            LEFT JOIN players p ON l.player_id = p.id
            LEFT JOIN teams t ON l.team_id = t.id
            LEFT JOIN leilao_proposta_jogadores lpj ON lp.id = lpj.proposta_id
            LEFT JOIN players po ON lpj.player_id = po.id
            WHERE lp.team_id = ?
            GROUP BY lp.id
            ORDER BY lp.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$team_id]);
    $propostas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'propostas' => $propostas]);
}

function propostasRecebidas($pdo, $team_id) {
    if (!$team_id) {
        echo json_encode(['success' => true, 'leiloes' => []]);
        return;
    }
    
    // Buscar leiloes dos meus jogadores que tem propostas
    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.*, 
                   COALESCE(l.temp_name, p.name) as player_name, 
                   COALESCE(l.temp_position, p.position) as position, 
                   COALESCE(l.temp_ovr, p.{$ovrColumn}) as ovr,
                   (SELECT COUNT(*) FROM leilao_propostas WHERE leilao_id = l.id) as total_propostas
            FROM leilao_jogadores l
            LEFT JOIN players p ON l.player_id = p.id
            WHERE l.team_id = ? AND l.status = 'ativo'
            HAVING total_propostas > 0";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$team_id]);
    $leiloes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'leiloes' => $leiloes]);
}

function verPropostas($pdo, $leilao_id, $team_id, $is_admin) {
    // Verificar se e dono do jogador ou admin
    $stmt = $pdo->prepare("SELECT team_id FROM leilao_jogadores WHERE id = ?");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch();
    
    if (!$leilao || (!$is_admin && $leilao['team_id'] != $team_id)) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }
    
    $sql = "SELECT lp.*, t.name as team_name
            FROM leilao_propostas lp
            JOIN teams t ON lp.team_id = t.id
            WHERE lp.leilao_id = ?
            ORDER BY lp.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$leilao_id]);
    $propostas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Para cada proposta, buscar os jogadores oferecidos
    foreach ($propostas as &$proposta) {
        $stmt2 = $pdo->prepare("SELECT p.* FROM players p 
                                JOIN leilao_proposta_jogadores lpj ON p.id = lpj.player_id 
                                WHERE lpj.proposta_id = ?");
        $stmt2->execute([$proposta['id']]);
        $proposta['jogadores'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        // picks oferecidas
        $stmt3 = $pdo->prepare("SELECT pk.id, pk.season_year, pk.round,
                                       CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
                                FROM leilao_proposta_picks lpp
                                JOIN picks pk ON pk.id = lpp.pick_id
                                LEFT JOIN teams t ON t.id = pk.original_team_id
                                WHERE lpp.proposta_id = ?");
        $stmt3->execute([$proposta['id']]);
        $proposta['picks'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode(['success' => true, 'propostas' => $propostas]);
}

function verPropostasEnviadas($pdo, $leilao_id, $team_id) {
    if (!$leilao_id) {
        echo json_encode(['success' => true, 'propostas' => []]);
        return;
    }

    $sql = "SELECT lp.*, t.name as team_name
            FROM leilao_propostas lp
            JOIN teams t ON lp.team_id = t.id
            WHERE lp.leilao_id = ?
            ORDER BY lp.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$leilao_id]);
    $propostas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($propostas as &$proposta) {
        $stmt2 = $pdo->prepare("SELECT p.* FROM players p
                                JOIN leilao_proposta_jogadores lpj ON p.id = lpj.player_id
                                WHERE lpj.proposta_id = ?");
        $stmt2->execute([$proposta['id']]);
        $proposta['jogadores'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $stmt3 = $pdo->prepare("SELECT pk.id, pk.season_year, pk.round,
                                       CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
                                FROM leilao_proposta_picks lpp
                                JOIN picks pk ON pk.id = lpp.pick_id
                                LEFT JOIN teams t ON t.id = pk.original_team_id
                                WHERE lpp.proposta_id = ?");
        $stmt3->execute([$proposta['id']]);
        $proposta['picks'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'propostas' => $propostas]);
}

function historicoLeiloes($pdo, $league_id) {
    $params = [];
    $where = "l.status = 'finalizado'";
    if ($league_id) {
        $where .= " AND l.league_id = ?";
        $params[] = $league_id;
    }
    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.id, l.data_fim, 
                   COALESCE(l.temp_name, p.name) as player_name,
                   COALESCE(t.name, 'Sem time') as team_name,
                   tw.city as winner_city, tw.name as winner_name
            FROM leilao_jogadores l
            LEFT JOIN players p ON l.player_id = p.id
            LEFT JOIN teams t ON l.team_id = t.id
            LEFT JOIN leilao_propostas lp ON lp.id = l.proposta_aceita_id
            LEFT JOIN teams tw ON lp.team_id = tw.id
            WHERE {$where}
            ORDER BY l.data_fim DESC
            LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $payload = array_map(static function ($row) {
        $winner = null;
        if (!empty($row['winner_name'])) {
            $winner = trim(($row['winner_city'] ?? '') . ' ' . $row['winner_name']);
        }
        return [
            'player_name' => $row['player_name'],
            'team_name' => $row['team_name'],
            'winner_team_name' => $winner,
            'data_fim' => $row['data_fim']
        ];
    }, $items);

    echo json_encode(['success' => true, 'leiloes' => $payload]);
}

// ========== FUNCOES POST ==========

function minhasPicks(PDO $pdo, ?int $team_id): void {
    if (!$team_id) {
        echo json_encode(['success' => true, 'picks' => []]);
        return;
    }
    $sql = "SELECT p.id, p.season_year, p.round, p.notes,
                   CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
            FROM picks p
            LEFT JOIN teams t ON p.original_team_id = t.id
            WHERE p.team_id = ?
            ORDER BY p.season_year DESC, p.round ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$team_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'picks' => $rows]);
}

function cadastrarLeilao($pdo, $body, $user_id) {
    $player_id = $body['player_id'] ?? null;
    $team_id = $body['team_id'] ?? null;
    $league_id = $body['league_id'] ?? null;
    $data_inicio = $body['data_inicio'] ?? null;
    $data_fim = $body['data_fim'] ?? null;
    $new_player = $body['new_player'] ?? null;
    $status = isset($body['status']) && $body['status'] === 'pendente' ? 'pendente' : 'ativo';

    if ((!$player_id && !$new_player) || !$league_id) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        return;
    }

    $tempPlayer = null;

    if ($new_player) {
        try {
            $tempPlayer = criarJogadorParaLeilao($pdo, $new_player, $user_id, $league_id);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            return;
        }
    }

    if ($player_id && !$team_id) {
        $stmt = $pdo->prepare("SELECT team_id FROM players WHERE id = ?");
        $stmt->execute([$player_id]);
        $playerRow = $stmt->fetch();
        if (!$playerRow) {
            echo json_encode(['success' => false, 'error' => 'Jogador nao encontrado']);
            return;
        }
        $team_id = $playerRow['team_id'];
    }
    
    // Verificar se jogador ja esta em leilao ativo
    $stmt = $pdo->prepare("SELECT id FROM leilao_jogadores WHERE player_id = ? AND status = 'ativo'");
    $stmt->execute([$player_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Jogador ja esta em leilao ativo']);
        return;
    }
    
    $data_inicio = $data_inicio ?: date('Y-m-d H:i:s');
    // Para pendente, mantenha data_fim nula para iniciar depois
    $data_fim = $status === 'ativo' ? ($data_fim ?: date('Y-m-d H:i:s', time() + (20 * 60))) : null;
    $stmt = $pdo->prepare("INSERT INTO leilao_jogadores (player_id, team_id, league_id, data_inicio, data_fim, status, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$player_id, $team_id, $league_id, $data_inicio, $data_fim, $status]);
    $leilaoId = $pdo->lastInsertId();

    if ($tempPlayer) {
        try {
            $stmtTemp = $pdo->prepare("UPDATE leilao_jogadores SET temp_name = ?, temp_position = ?, temp_age = ?, temp_ovr = ?, is_temp_player = 1 WHERE id = ?");
            $stmtTemp->execute([$tempPlayer['name'], $tempPlayer['position'], $tempPlayer['age'], $tempPlayer['ovr'], $leilaoId]);
        } catch (Throwable $e) {
            // ignora caso as colunas temporárias não existam por algum motivo
        }
    }
    
    echo json_encode(['success' => true, 'leilao_id' => $leilaoId]);
}

function iniciarLeilao($pdo, $body) {
    if (!isset($_SESSION['is_admin']) && (($_SESSION['user_type'] ?? '') !== 'admin')) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }
    $leilao_id = $body['leilao_id'] ?? null;
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilao nao informado']);
        return;
    }
    $stmt = $pdo->prepare("UPDATE leilao_jogadores SET status = 'ativo', data_inicio = NOW(), data_fim = DATE_ADD(NOW(), INTERVAL 20 MINUTE) WHERE id = ?");
    $stmt->execute([$leilao_id]);
    echo json_encode(['success' => true]);
}

function removerTempLeilao($pdo, $body) {
    if (!isset($_SESSION['is_admin']) && (($_SESSION['user_type'] ?? '') !== 'admin')) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }
    $leilao_id = $body['leilao_id'] ?? null;
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilao nao informado']);
        return;
    }
    // Remove somente registros pendentes e criados como temporarios
    $stmt = $pdo->prepare("DELETE FROM leilao_jogadores WHERE id = ? AND status = 'pendente' AND (is_temp_player = 1 OR temp_name IS NOT NULL)");
    $stmt->execute([$leilao_id]);
    echo json_encode(['success' => true]);
}

function cancelarLeilao($pdo, $body) {
    $leilao_id = $body['leilao_id'] ?? null;
    
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilao nao informado']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE leilao_jogadores SET status = 'cancelado' WHERE id = ?");
    $stmt->execute([$leilao_id]);
    
    // Atualizar todas as propostas para recusadas
    $stmt = $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE leilao_id = ?");
    $stmt->execute([$leilao_id]);
    
    echo json_encode(['success' => true]);
}

function enviarProposta($pdo, $body, $team_id) {
    if (!$team_id) {
        echo json_encode(['success' => false, 'error' => 'Voce precisa ter um time para enviar propostas']);
        return;
    }
    
    $leilao_id = $body['leilao_id'] ?? null;
    $player_ids = $body['player_ids'] ?? [];
    $pick_ids = $body['pick_ids'] ?? [];
    $notas = $body['notas'] ?? '';
    
    if (!$leilao_id || (empty($player_ids) && empty($pick_ids) && trim($notas) === '')) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        return;
    }
    
    // Verificar se leilao existe e esta ativo
    $stmt = $pdo->prepare("SELECT * FROM leilao_jogadores WHERE id = ? AND status = 'ativo'");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch();
    
    if (!$leilao) {
        echo json_encode(['success' => false, 'error' => 'Leilao nao encontrado ou nao esta ativo']);
        return;
    }

    if (!empty($leilao['data_fim']) && strtotime($leilao['data_fim']) <= time()) {
        echo json_encode(['success' => false, 'error' => 'Leilao encerrado']);
        return;
    }
    
    // Nao pode enviar proposta para proprio jogador
    if ($leilao['team_id'] == $team_id) {
        echo json_encode(['success' => false, 'error' => 'Voce nao pode enviar proposta para seu proprio jogador']);
        return;
    }
    
    // Verificar se ja enviou proposta para este leilao
    $stmt = $pdo->prepare("SELECT id FROM leilao_propostas WHERE leilao_id = ? AND team_id = ? AND status = 'pendente'");
    $stmt->execute([$leilao_id, $team_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Voce ja tem uma proposta pendente para este leilao']);
        return;
    }

    // Se o leilão não tem time (jogador criado), não aceitar picks
    if (!empty($pick_ids) && empty($leilao['team_id'])) {
        echo json_encode(['success' => false, 'error' => 'Este leilao nao aceita picks (jogador sem time).']);
        return;
    }
    
    if (!empty($player_ids)) {
        $placeholders = implode(',', array_fill(0, count($player_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM players WHERE id IN ($placeholders) AND team_id = ?");
        $params = array_merge($player_ids, [$team_id]);
        $stmt->execute($params);
        $jogadores_validos = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($jogadores_validos) !== count($player_ids)) {
            echo json_encode(['success' => false, 'error' => 'Alguns jogadores selecionados nao pertencem ao seu time']);
            return;
        }
    }

    if (!empty($pick_ids)) {
        $placeholders = implode(',', array_fill(0, count($pick_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM picks WHERE id IN ($placeholders) AND team_id = ?");
        $params = array_merge($pick_ids, [$team_id]);
        $stmt->execute($params);
        $picks_validas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($picks_validas) !== count($pick_ids)) {
            echo json_encode(['success' => false, 'error' => 'Algumas picks nao pertencem ao seu time']);
            return;
        }
    }
    
    $pdo->beginTransaction();
    
    try {
        // Criar proposta
        $stmt = $pdo->prepare("INSERT INTO leilao_propostas (leilao_id, team_id, notas, status, created_at) VALUES (?, ?, ?, 'pendente', NOW())");
        $stmt->execute([$leilao_id, $team_id, $notas]);
        $proposta_id = $pdo->lastInsertId();
        
        // Adicionar jogadores da proposta
        if (!empty($player_ids)) {
            $stmt = $pdo->prepare("INSERT INTO leilao_proposta_jogadores (proposta_id, player_id) VALUES (?, ?)");
            foreach ($player_ids as $pid) {
                $stmt->execute([$proposta_id, $pid]);
            }
        }
        // Adicionar picks da proposta
        if (!empty($pick_ids)) {
            $stmt = $pdo->prepare("INSERT INTO leilao_proposta_picks (proposta_id, pick_id) VALUES (?, ?)");
            foreach ($pick_ids as $pkid) {
                $stmt->execute([$proposta_id, $pkid]);
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'proposta_id' => $proposta_id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao salvar proposta: ' . $e->getMessage()]);
    }
}

function aceitarProposta($pdo, $body, $team_id, $is_admin) {
    $proposta_id = $body['proposta_id'] ?? null;
    
    if (!$proposta_id) {
        echo json_encode(['success' => false, 'error' => 'ID da proposta nao informado']);
        return;
    }
    
    // Buscar proposta e leilao
    $stmt = $pdo->prepare("SELECT lp.*, l.player_id, l.team_id as leilao_team_id, l.id as leilao_id, l.data_fim,
                           l.is_temp_player, l.temp_name, l.temp_position, l.temp_age, l.temp_ovr
                           FROM leilao_propostas lp
                           JOIN leilao_jogadores l ON lp.leilao_id = l.id
                           WHERE lp.id = ?");
    $stmt->execute([$proposta_id]);
    $proposta = $stmt->fetch();
    
    if (!$proposta) {
        echo json_encode(['success' => false, 'error' => 'Proposta nao encontrada']);
        return;
    }
    
    // Verificar se e dono do jogador ou admin
    if (!$is_admin) {
        if (!empty($proposta['leilao_team_id']) && $proposta['leilao_team_id'] != $team_id) {
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            return;
        }
        if (empty($proposta['leilao_team_id'])) {
            echo json_encode(['success' => false, 'error' => 'Somente admin pode aceitar este leilao sem time de origem']);
            return;
        }
    }

    if (!empty($proposta['data_fim']) && strtotime($proposta['data_fim']) > time()) {
        echo json_encode(['success' => false, 'error' => 'Leilao ainda esta em andamento']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        // Marcar proposta como aceita
        $stmt = $pdo->prepare("UPDATE leilao_propostas SET status = 'aceita' WHERE id = ?");
        $stmt->execute([$proposta_id]);
        
        // Marcar outras propostas como recusadas
        $stmt = $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE leilao_id = ? AND id != ?");
        $stmt->execute([$proposta['leilao_id'], $proposta_id]);
        
        // Finalizar leilao
        $stmt = $pdo->prepare("UPDATE leilao_jogadores SET status = 'finalizado', proposta_aceita_id = ? WHERE id = ?");
        $stmt->execute([$proposta_id, $proposta['leilao_id']]);
        
        // Buscar jogadores oferecidos na proposta
        $stmt = $pdo->prepare("SELECT player_id FROM leilao_proposta_jogadores WHERE proposta_id = ?");
        $stmt->execute([$proposta_id]);
        $jogadores_oferecidos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        // Buscar picks oferecidas na proposta
        $stmt = $pdo->prepare("SELECT pick_id FROM leilao_proposta_picks WHERE proposta_id = ?");
        $stmt->execute([$proposta_id]);
        $picks_oferecidas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $winnerTeamId = $proposta['team_id'];

        $transferStmt = $pdo->prepare("UPDATE players SET team_id = ? WHERE id = ?");

        // Se for jogador criado especificamente para o leilao, criar no time vencedor agora
        if (empty($proposta['player_id']) && !empty($proposta['is_temp_player'])) {
            $ovrColumn = playerOvrColumn($pdo);
            $stmtCreate = $pdo->prepare("INSERT INTO players (team_id, name, age, position, {$ovrColumn}) VALUES (?, ?, ?, ?, ?)");
            $stmtCreate->execute([
                $winnerTeamId,
                $proposta['temp_name'],
                $proposta['temp_age'],
                $proposta['temp_position'],
                $proposta['temp_ovr']
            ]);
            $proposta['player_id'] = $pdo->lastInsertId();
            $updateLeilao = $pdo->prepare("UPDATE leilao_jogadores SET player_id = ?, team_id = ? WHERE id = ?");
            $updateLeilao->execute([$proposta['player_id'], $winnerTeamId, $proposta['leilao_id']]);
        }

        // Transferir jogador do leilao para o time que fez a proposta (se existir player_id)
        if (!empty($proposta['player_id'])) {
            $transferStmt->execute([$winnerTeamId, $proposta['player_id']]);
        }
        
        // Transferir jogadores oferecidos para o time do leilao
        if (!empty($proposta['leilao_team_id'])) {
            foreach ($jogadores_oferecidos as $player_id) {
                $transferStmt->execute([$proposta['leilao_team_id'], $player_id]);
            }
            // Transferir picks oferecidas para o time do leilao
            if (!empty($picks_oferecidas)) {
                $placeholders = implode(',', array_fill(0, count($picks_oferecidas), '?'));
                $params = array_merge([$proposta['leilao_team_id']], $picks_oferecidas);
                $pdo->prepare("UPDATE picks SET team_id = ? WHERE id IN ($placeholders)")->execute($params);
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Troca realizada com sucesso']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao processar troca: ' . $e->getMessage()]);
    }
}

function recusarProposta($pdo, $body, $team_id, $is_admin) {
    $proposta_id = $body['proposta_id'] ?? null;
    
    if (!$proposta_id) {
        echo json_encode(['success' => false, 'error' => 'ID da proposta nao informado']);
        return;
    }
    
    // Buscar proposta e leilao
    $stmt = $pdo->prepare("SELECT lp.*, l.team_id as leilao_team_id
                           FROM leilao_propostas lp
                           JOIN leilao_jogadores l ON lp.leilao_id = l.id
                           WHERE lp.id = ?");
    $stmt->execute([$proposta_id]);
    $proposta = $stmt->fetch();
    
    if (!$proposta) {
        echo json_encode(['success' => false, 'error' => 'Proposta nao encontrada']);
        return;
    }
    
    // Verificar se e dono do jogador ou admin
    if (!$is_admin && $proposta['leilao_team_id'] != $team_id) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE id = ?");
    $stmt->execute([$proposta_id]);
    
    echo json_encode(['success' => true]);
}
