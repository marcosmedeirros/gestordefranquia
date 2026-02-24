<?php
session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

function teamColumnExists(PDO $pdo, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM teams LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

function appendPhoneFields(array &$row): void {
    $rawPhone = $row['owner_phone'] ?? '';
    $normalizedPhone = $rawPhone !== '' ? normalizeBrazilianPhone($rawPhone) : null;
    if (!$normalizedPhone && $rawPhone !== '') {
        $digits = preg_replace('/\D+/', '', $rawPhone);
        if ($digits !== '') {
            $normalizedPhone = str_starts_with($digits, '55') ? $digits : '55' . $digits;
        }
    }
    $row['owner_phone_display'] = $rawPhone !== '' ? formatBrazilianPhone($rawPhone) : null;
    $row['owner_phone_whatsapp'] = $normalizedPhone;
}

/**
 * Sincroniza contador de trades por time com base em current_cycle/trades_cycle.
 * Retorna o valor atual de trades_used (0 quando reseta ou não encontrado).
 */
function syncTeamTradeCounterLocal(PDO $pdo, int $teamId): int {
    try {
        $stmt = $pdo->prepare('SELECT current_cycle, trades_cycle, trades_used FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 0;
        $currentCycle = (int)($row['current_cycle'] ?? 0);
        $tradesCycle = (int)($row['trades_cycle'] ?? 0);
        $tradesUsed = (int)($row['trades_used'] ?? 0);

        if ($currentCycle > 0 && $tradesCycle !== $currentCycle) {
            $pdo->prepare('UPDATE teams SET trades_used = 0, trades_cycle = ? WHERE id = ?')
                ->execute([$currentCycle, $teamId]);
            return 0;
        }

        if ($currentCycle > 0 && $tradesCycle <= 0) {
            $pdo->prepare('UPDATE teams SET trades_cycle = ? WHERE id = ?')
                ->execute([$currentCycle, $teamId]);
        }

        return $tradesUsed;
    } catch (Exception $e) {
        return 0;
    }
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? null;
    if ($action === 'list_players' || $action === 'search_player') {
        $user = getUserSession();
        if (!$user) {
            jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
        }
        $isAdmin = ($user['user_type'] ?? '') === 'admin' || !empty($_SESSION['is_admin']);
        $league = $user['league'] ?? 'ROOKIE';
        $leagueParam = strtoupper(trim($_GET['league'] ?? ''));
        if ($leagueParam !== '' && $isAdmin) {
            $league = $leagueParam;
        }
        $currentUserId = $user['id'];
    }

    if ($action === 'list_players') {
        $query = trim($_GET['query'] ?? '');
        $position = strtoupper(trim($_GET['position'] ?? ''));
        $teamFilter = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
    $ovrMin = isset($_GET['ovr_min']) ? (int)$_GET['ovr_min'] : null;
    $ovrMax = isset($_GET['ovr_max']) ? (int)$_GET['ovr_max'] : null;
    $ageMin = isset($_GET['age_min']) ? (int)$_GET['age_min'] : null;
    $ageMax = isset($_GET['age_max']) ? (int)$_GET['age_max'] : null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 50);
        if ($perPage <= 0) $perPage = 50;
        if ($perPage > 200) $perPage = 200;
        $offset = ($page - 1) * $perPage;
        $params = [$league];
        $where = 't.league = ?';
        if ($query !== '') {
            $where .= ' AND p.name LIKE ?';
            $params[] = '%' . $query . '%';
        }
        if ($position !== '') {
            $where .= ' AND p.position = ?';
            $params[] = $position;
        }
        if ($ovrMin !== null && $ovrMin > 0) {
            $where .= ' AND p.ovr >= ?';
            $params[] = $ovrMin;
        }
        if ($ovrMax !== null && $ovrMax > 0) {
            $where .= ' AND p.ovr <= ?';
            $params[] = $ovrMax;
        }
        if ($ageMin !== null && $ageMin > 0) {
            $where .= ' AND p.age >= ?';
            $params[] = $ageMin;
        }
        if ($ageMax !== null && $ageMax > 0) {
            $where .= ' AND p.age <= ?';
            $params[] = $ageMax;
        }
        if ($teamFilter > 0) {
            $where .= ' AND t.id = ?';
            $params[] = $teamFilter;
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*)
            FROM players p
            JOIN teams t ON p.team_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
                 SELECT p.id, p.name, p.nba_player_id, p.age, p.ovr, p.position, p.secondary_position,
                   t.id as team_id, t.city, t.name as team_name, t.league,
                   u.phone as owner_phone
            FROM players p
            JOIN teams t ON p.team_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE {$where}
            ORDER BY p.ovr DESC, p.name ASC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($players as &$player) {
            appendPhoneFields($player);
        }
        unset($player);

        jsonResponse(200, [
            'players' => $players,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1
            ]
        ]);
    }

    if ($action === 'search_player') {
        $query = trim($_GET['query'] ?? '');
        if ($query === '' || mb_strlen($query) < 2) {
            jsonResponse(200, ['players' => []]);
        }
        $stmt = $pdo->prepare('
                 SELECT p.id, p.name, p.nba_player_id, p.age, p.ovr, p.position, p.secondary_position,
                   t.id as team_id, t.city, t.name as team_name, t.league,
                   u.phone as owner_phone
            FROM players p
            JOIN teams t ON p.team_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE t.league = ? AND p.name LIKE ?
            ORDER BY p.ovr DESC, p.name ASC
            LIMIT 50
        ');
        $stmt->execute([$league, '%' . $query . '%']);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($players as &$player) {
            appendPhoneFields($player);
        }
        unset($player);

        jsonResponse(200, ['players' => $players]);
    }


    $teamId = isset($_GET['id']) ? (int) $_GET['id'] : null;
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
    $leagueParam = isset($_GET['league']) ? strtoupper(trim($_GET['league'])) : null;

    // Obter league do usuário da sessão ou do time vinculado
    $user = getUserSession();
    if (!$user) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }

    $league = null;
    $isAdmin = ($user['user_type'] ?? '') === 'admin' || !empty($_SESSION['is_admin']);

    if ($leagueParam && $isAdmin) {
        $league = $leagueParam;
    } else {
        $teamLeagueStmt = $pdo->prepare('SELECT league FROM teams WHERE user_id = ? LIMIT 1');
        $teamLeagueStmt->execute([$user['id']]);
        $teamLeague = $teamLeagueStmt->fetch();
        if ($teamLeague && !empty($teamLeague['league'])) {
            $league = $teamLeague['league'];
        }
    }

    if (!$league) {
        $league = $user['league'] ?? 'ROOKIE';
    }
    
    $sql = 'SELECT t.id, t.name, t.city, t.mascot, t.photo_url, t.league, t.division_id, d.name AS division_name, t.user_id, u.photo_url AS user_photo, u.name AS owner_name, u.phone AS owner_phone
            FROM teams t
            LEFT JOIN divisions d ON d.id = t.division_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.league = ?';
    $params = [$league];
    $clauses = [];
    if ($teamId) {
        $clauses[] = 't.id = ?';
        $params[] = $teamId;
    }
    if ($userId) {
        $clauses[] = 't.user_id = ?';
        $params[] = $userId;
    }
    if ($clauses) {
        $sql .= ' AND ' . implode(' AND ', $clauses);
    }
    $sql .= ' ORDER BY t.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $teams = $stmt->fetchAll();

    foreach ($teams as &$team) {
        $team['cap_top8'] = topEightCap($pdo, (int) $team['id']);
        $rawPhone = $team['owner_phone'] ?? '';
        $normalizedPhone = $rawPhone !== '' ? normalizeBrazilianPhone($rawPhone) : null;
        if (!$normalizedPhone && $rawPhone !== '') {
            $digits = preg_replace('/\D+/', '', $rawPhone);
            if ($digits !== '') {
                $normalizedPhone = str_starts_with($digits, '55') ? $digits : '55' . $digits;
            }
        }
        $team['owner_phone_display'] = $rawPhone !== '' ? formatBrazilianPhone($rawPhone) : null;
        $team['owner_phone_whatsapp'] = $normalizedPhone;
        // Sincronizar e expor contador de trades por time
        $team['trades_used'] = syncTeamTradeCounterLocal($pdo, (int)$team['id']);
    }

    jsonResponse(200, ['teams' => $teams]);
}

if ($method === 'POST') {
    $body = readJsonBody();
    $name = trim($body['name'] ?? '');
    $city = trim($body['city'] ?? '');
    $mascot = trim($body['mascot'] ?? '');
    $conference = strtoupper(trim($body['conference'] ?? ''));
    $divisionId = $body['division_id'] ?? null;
    $userId = $body['user_id'] ?? null;
    $photoUrl = trim($body['photo_url'] ?? '');
    
    // Obter usuário e liga da sessão quando user_id não for fornecido
    $sessionUser = getUserSession();
    if (!$userId && isset($sessionUser['id'])) {
        $userId = (int) $sessionUser['id'];
    }
    if (!$userId) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }

    // Obter league do usuário
    $userStmt = $pdo->prepare('SELECT league FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch();
    if (!$userRow) {
        jsonResponse(404, ['error' => 'Usuário não encontrado.']);
    }
    $league = $sessionUser['league'] ?? $userRow['league'];

    // Mascote é opcional no onboarding; permitir vazio
    if ($name === '' || $city === '') {
        jsonResponse(422, ['error' => 'Nome e cidade são obrigatórios.']);
    }
    // Conferência é obrigatória somente se coluna existir
    $hasConference = teamColumnExists($pdo, 'conference');
    if ($hasConference) {
        if (!in_array($conference, ['LESTE', 'OESTE'], true)) {
            jsonResponse(422, ['error' => 'Conferência inválida. Escolha LESTE ou OESTE.']);
        }
    }

    // Se a foto vier como data URL, salvar em img/teams e substituir por caminho relativo
    if ($photoUrl && str_starts_with($photoUrl, 'data:image/')) {
        $savedPath = null;
        try {
            $commaPos = strpos($photoUrl, ',');
            $meta = substr($photoUrl, 0, $commaPos);
            $base64 = substr($photoUrl, $commaPos + 1);
            $mime = null;
            if (preg_match('/data:(image\/(png|jpeg|jpg|webp));base64/i', $meta, $m)) {
                $mime = strtolower($m[1]);
            }
            $ext = 'png';
            if ($mime === 'image/jpeg' || $mime === 'image/jpg') { $ext = 'jpg'; }
            if ($mime === 'image/webp') { $ext = 'webp'; }
            $binary = base64_decode($base64);
            if ($binary === false) { throw new Exception('Falha ao decodificar imagem.'); }

            $dirFs = __DIR__ . '/../img/teams';
            if (!is_dir($dirFs)) { @mkdir($dirFs, 0775, true); }
            $filename = 'team-' . $userId . '-' . time() . '.' . $ext;
            $fullPath = $dirFs . '/' . $filename;
            if (file_put_contents($fullPath, $binary) === false) {
                throw new Exception('Falha ao salvar imagem.');
            }
            // Caminho público
            $savedPath = '/img/teams/' . $filename;
            $photoUrl = $savedPath;
        } catch (Exception $e) {
            // Se falhar, ignora a foto para não quebrar o cadastro
            $photoUrl = '';
        }
    }

    if ($divisionId) {
        $checkDiv = $pdo->prepare('SELECT id FROM divisions WHERE id = ?');
        $checkDiv->execute([$divisionId]);
        if (!$checkDiv->fetch()) {
            jsonResponse(404, ['error' => 'Divisão não encontrada.']);
        }
    }

    if ($hasConference) {
        $stmt = $pdo->prepare('INSERT INTO teams (user_id, league, conference, name, city, mascot, division_id, photo_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $league, $conference, $name, $city, $mascot !== '' ? $mascot : '', $divisionId, $photoUrl ?: null]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO teams (user_id, league, name, city, mascot, division_id, photo_url) VALUES (?, ?, ?, ?, ?, ?, ?)');
        // Mascote não pode ser NULL na tabela; use string vazia quando não fornecido
        $stmt->execute([$userId, $league, $name, $city, $mascot !== '' ? $mascot : '', $divisionId, $photoUrl ?: null]);
    }
    $teamId = (int) $pdo->lastInsertId();

    jsonResponse(201, ['message' => 'Time criado.', 'team_id' => $teamId]);
}

if ($method === 'PUT') {
    $body = readJsonBody();
    $name = trim($body['name'] ?? '');
    $city = trim($body['city'] ?? '');
    $mascot = trim($body['mascot'] ?? '');
    $conference = strtoupper(trim($body['conference'] ?? ''));
    $photoUrl = trim($body['photo_url'] ?? '');

    $sessionUser = getUserSession();
    if (!isset($sessionUser['id'])) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }
    $userId = (int) $sessionUser['id'];

    // Buscar time do usuário
    $stmt = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $team = $stmt->fetch();
    if (!$team) {
        jsonResponse(404, ['error' => 'Time não encontrado para o usuário.']);
    }

    // Salvar logo se vier como data URL
    if ($photoUrl && str_starts_with($photoUrl, 'data:image/')) {
        try {
            $commaPos = strpos($photoUrl, ',');
            $meta = substr($photoUrl, 0, $commaPos);
            $base64 = substr($photoUrl, $commaPos + 1);
            $mime = null;
            if (preg_match('/data:(image\/(png|jpeg|jpg|webp));base64/i', $meta, $m)) {
                $mime = strtolower($m[1]);
            }
            $ext = 'png';
            if ($mime === 'image/jpeg' || $mime === 'image/jpg') { $ext = 'jpg'; }
            if ($mime === 'image/webp') { $ext = 'webp'; }
            $binary = base64_decode($base64);
            if ($binary === false) { throw new Exception('Falha ao decodificar imagem.'); }

            $dirFs = __DIR__ . '/../img/teams';
            if (!is_dir($dirFs)) { @mkdir($dirFs, 0775, true); }
            $filename = 'team-' . $userId . '-' . time() . '.' . $ext;
            $fullPath = $dirFs . '/' . $filename;
            if (file_put_contents($fullPath, $binary) === false) {
                throw new Exception('Falha ao salvar imagem.');
            }
            $photoUrl = '/img/teams/' . $filename;
        } catch (Exception $e) {
            $photoUrl = '';
        }
    }

    $hasConference = teamColumnExists($pdo, 'conference');
    if ($hasConference && $conference !== '' && !in_array($conference, ['LESTE', 'OESTE'], true)) {
        jsonResponse(422, ['error' => 'Conferência inválida.']);
    }

    if ($hasConference) {
        $upd = $pdo->prepare('UPDATE teams SET name = ?, city = ?, mascot = ?, photo_url = ?, conference = ? WHERE id = ?');
        $upd->execute([
            $name !== '' ? $name : $team['name'],
            $city !== '' ? $city : $team['city'],
            $mascot !== '' ? $mascot : '',
            $photoUrl !== '' ? $photoUrl : $team['photo_url'],
            $conference !== '' ? $conference : $team['conference'] ?? null,
            (int) $team['id'],
        ]);
    } else {
        $upd = $pdo->prepare('UPDATE teams SET name = ?, city = ?, mascot = ?, photo_url = ? WHERE id = ?');
        $upd->execute([
            $name !== '' ? $name : $team['name'],
            $city !== '' ? $city : $team['city'],
            $mascot !== '' ? $mascot : '',
            $photoUrl !== '' ? $photoUrl : $team['photo_url'],
            (int) $team['id'],
        ]);
    }

    jsonResponse(200, ['message' => 'Time atualizado.']);
}

jsonResponse(405, ['error' => 'Method not allowed']);
