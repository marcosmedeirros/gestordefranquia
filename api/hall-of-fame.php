<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

requireAuth();

$pdo = db();

function ensureHallOfFameTable(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'hall_of_fame'");
        if ($stmt->rowCount() > 0) {
            return;
        }
        $pdo->exec("CREATE TABLE hall_of_fame (
            id INT AUTO_INCREMENT PRIMARY KEY,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            league ENUM('ELITE','NEXT','RISE','ROOKIE') NULL,
            team_id INT NULL,
            team_name VARCHAR(255) NULL,
            gm_name VARCHAR(255) NULL,
            titles INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_hof_titles (titles)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {
        // ignore
    }
}

ensureHallOfFameTable($pdo);

try {
    $query = "
        SELECT
            hof.*, 
            t.city AS team_city,
            t.name AS team_name_live,
            u.name AS gm_name_live
        FROM hall_of_fame hof
        LEFT JOIN teams t ON hof.team_id = t.id
        LEFT JOIN users u ON t.user_id = u.id
        ORDER BY hof.titles DESC, COALESCE(hof.team_name, t.name) ASC, hof.id DESC
    ";
    $stmt = $pdo->query($query);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map(static function (array $row): array {
        $isActive = (int)($row['is_active'] ?? 0) === 1;
        $teamName = $isActive
            ? trim(($row['team_city'] ?? '') . ' ' . ($row['team_name_live'] ?? ''))
            : (string)($row['team_name'] ?? '');
        if ($teamName === '') {
            $teamName = (string)($row['team_name'] ?? '');
        }
        $gmName = $isActive ? ($row['gm_name_live'] ?? '') : ($row['gm_name'] ?? '');
        if (!$gmName) {
            $gmName = $row['gm_name'] ?? '';
        }
        return [
            'id' => (int)$row['id'],
            'is_active' => $isActive ? 1 : 0,
            'league' => $row['league'] ?? null,
            'team_name' => $teamName,
            'gm_name' => $gmName,
            'titles' => (int)($row['titles'] ?? 0)
        ];
    }, $rows);

    echo json_encode(['success' => true, 'items' => $items]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao carregar Hall da Fama']);
}
