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

if ($method === 'GET') {
    $teamId = isset($_GET['id']) ? (int) $_GET['id'] : null;
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
    
    // Obter league do usuário da sessão
    $user = getUserSession();
    $league = $user['league'] ?? 'ROOKIE';
    
    $sql = 'SELECT t.id, t.name, t.city, t.mascot, t.photo_url, t.league, t.division_id, d.name AS division_name, t.user_id, u.photo_url AS user_photo
            FROM teams t
            LEFT JOIN divisions d ON d.id = t.division_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.league = ?';
    $params = [$league];
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

    // Cada time ganha 1 pick de 1ª e 2ª rodada para o ano corrente.
    $seasonYear = (int) date('Y');
    $pickStmt = $pdo->prepare('INSERT INTO picks (team_id, original_team_id, season_year, round, notes) VALUES (?, ?, ?, ?, ?)');
    $pickStmt->execute([$teamId, $teamId, $seasonYear, '1', 'Pick de 1ª rodada gerada automaticamente.']);
    $pickStmt->execute([$teamId, $teamId, $seasonYear, '2', 'Pick de 2ª rodada gerada automaticamente.']);

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
