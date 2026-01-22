<?php
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $year = isset($_GET['year']) ? (int) $_GET['year'] : null;
    $league = isset($_GET['league']) ? $_GET['league'] : null;
    
    $sql = 'SELECT id, year, league FROM drafts';
    $params = [];
    $where = [];
    
    if ($year) {
        $where[] = 'year = ?';
        $params[] = $year;
    }
    if ($league) {
        $where[] = 'league = ?';
        $params[] = $league;
    }
    
    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY year DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $drafts = $stmt->fetchAll();

    foreach ($drafts as &$draft) {
        $p = $pdo->prepare('SELECT id, name, position, age, ovr FROM draft_players WHERE draft_id = ? ORDER BY ovr DESC');
        $p->execute([$draft['id']]);
        $draft['players'] = $p->fetchAll();
    }

    jsonResponse(200, ['drafts' => $drafts]);
}

if ($method === 'POST') {
    $action = isset($_GET['action']) ? $_GET['action'] : null;
    
    // Get or Create draft
    if ($action === 'get_or_create') {
        $year = isset($_GET['year']) ? (int) $_GET['year'] : 0;
        $league = isset($_GET['league']) ? $_GET['league'] : null;
        
        if (!$year || !$league) {
            jsonResponse(422, ['error' => 'Ano e liga são obrigatórios.']);
        }
        
        // Busca draft existente
        $stmt = $pdo->prepare('SELECT id, year, league FROM drafts WHERE year = ? AND league = ?');
        $stmt->execute([$year, $league]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            jsonResponse(200, ['draft' => $existing, 'created' => false]);
        }
        
        // Cria novo draft
        try {
            $stmt = $pdo->prepare('INSERT INTO drafts (year, league) VALUES (?, ?)');
            $stmt->execute([$year, $league]);
            $draftId = (int) $pdo->lastInsertId();
            
            jsonResponse(201, [
                'draft' => [
                    'id' => $draftId,
                    'year' => $year,
                    'league' => $league
                ],
                'created' => true
            ]);
        } catch (Throwable $e) {
            jsonResponse(500, ['error' => 'Erro ao criar draft.', 'details' => $e->getMessage()]);
        }
    }
    
    // Create draft com jogadores
    $body = readJsonBody();
    $year = (int) ($body['year'] ?? 0);
    $league = $body['league'] ?? null;
    $players = $body['players'] ?? [];

    if (!$year) {
        jsonResponse(422, ['error' => 'Ano é obrigatório.']);
    }
    
    if (!$league) {
        jsonResponse(422, ['error' => 'Liga é obrigatória.']);
    }

    $exists = $pdo->prepare('SELECT id FROM drafts WHERE year = ? AND league = ?');
    $exists->execute([$year, $league]);
    if ($exists->fetch()) {
        jsonResponse(409, ['error' => 'Draft para esse ano e liga já existe.']);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO drafts (year, league) VALUES (?, ?)');
        $stmt->execute([$year, $league]);
        $draftId = (int) $pdo->lastInsertId();

        if (is_array($players)) {
            $playerStmt = $pdo->prepare('INSERT INTO draft_players (draft_id, name, position, age, ovr) VALUES (?, ?, ?, ?, ?)');
            foreach ($players as $player) {
                $playerStmt->execute([
                    $draftId,
                    trim($player['name'] ?? ''),
                    trim($player['position'] ?? ''),
                    (int) ($player['age'] ?? 0),
                    (int) ($player['ovr'] ?? 0),
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => 'Erro ao criar draft.', 'details' => $e->getMessage()]);
    }

    jsonResponse(201, ['message' => 'Draft criado.', 'draft_id' => $draftId]);
}

jsonResponse(405, ['error' => 'Method not allowed']);
