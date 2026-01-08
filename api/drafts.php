<?php
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $year = isset($_GET['year']) ? (int) $_GET['year'] : null;
    $sql = 'SELECT id, year FROM drafts';
    $params = [];
    if ($year) {
        $sql .= ' WHERE year = ?';
        $params[] = $year;
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
    $body = readJsonBody();
    $year = (int) ($body['year'] ?? 0);
    $players = $body['players'] ?? [];

    if (!$year) {
        jsonResponse(422, ['error' => 'Ano é obrigatório.']);
    }

    $exists = $pdo->prepare('SELECT id FROM drafts WHERE year = ?');
    $exists->execute([$year]);
    if ($exists->fetch()) {
        jsonResponse(409, ['error' => 'Draft para esse ano já existe.']);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO drafts (year) VALUES (?)');
        $stmt->execute([$year]);
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
