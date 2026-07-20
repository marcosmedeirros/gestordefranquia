<?php
/**
 * Favoritos de jogadores (watchlist).
 * Usa a tabela player_favorites (user_id, player_id).
 *
 * GET  ?action=list        → { ids: [1,2,3] }  (ids favoritados do usuário)
 * GET  ?action=list_full   → { players: [...] } (jogadores favoritados com dados)
 * POST { action:'toggle', player_id } → { favorited: bool }
 * POST { action:'add'|'remove', player_id }
 */
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

$user = getUserSession();
if (!$user) jsonResponse(401, ['error' => 'Não autenticado']);
$userId = (int)$user['id'];

// Garantir tabela (idempotente)
$pdo->exec("CREATE TABLE IF NOT EXISTS player_favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    player_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_player (user_id, player_id),
    INDEX idx_pf_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $stmt = $pdo->prepare('SELECT player_id FROM player_favorites WHERE user_id = ?');
        $stmt->execute([$userId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        jsonResponse(200, ['ids' => $ids]);
    }

    if ($action === 'list_full') {
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.position, p.age, p.ovr,
                   p.nba_player_id, p.foto_adicional,
                   t.id AS team_id, t.city, t.name AS team_name, t.photo_url AS team_photo
            FROM player_favorites pf
            JOIN players p ON p.id = pf.player_id
            LEFT JOIN teams t ON t.id = p.team_id
            WHERE pf.user_id = ?
            ORDER BY p.ovr DESC, p.name ASC
        ");
        $stmt->execute([$userId]);
        jsonResponse(200, ['players' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    jsonResponse(400, ['error' => 'Ação inválida']);
}

if ($method === 'POST') {
    $body = readJsonBody();
    $action = $body['action'] ?? 'toggle';
    $playerId = isset($body['player_id']) ? (int)$body['player_id'] : 0;
    if ($playerId <= 0) jsonResponse(422, ['error' => 'player_id é obrigatório']);

    // Confirmar que o jogador existe
    $chk = $pdo->prepare('SELECT id FROM players WHERE id = ? LIMIT 1');
    $chk->execute([$playerId]);
    if (!$chk->fetch()) jsonResponse(404, ['error' => 'Jogador não encontrado']);

    $existsStmt = $pdo->prepare('SELECT id FROM player_favorites WHERE user_id = ? AND player_id = ? LIMIT 1');
    $existsStmt->execute([$userId, $playerId]);
    $isFav = (bool)$existsStmt->fetch();

    if ($action === 'add' || ($action === 'toggle' && !$isFav)) {
        $pdo->prepare('INSERT IGNORE INTO player_favorites (user_id, player_id) VALUES (?, ?)')->execute([$userId, $playerId]);
        jsonResponse(200, ['favorited' => true]);
    }
    if ($action === 'remove' || ($action === 'toggle' && $isFav)) {
        $pdo->prepare('DELETE FROM player_favorites WHERE user_id = ? AND player_id = ?')->execute([$userId, $playerId]);
        jsonResponse(200, ['favorited' => false]);
    }

    jsonResponse(400, ['error' => 'Ação inválida']);
}

jsonResponse(405, ['error' => 'Método não permitido']);
