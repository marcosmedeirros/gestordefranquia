<?php
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
    echo json_encode(['success' => true, 'groups' => getHallOfFameGrouped($pdo)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao carregar Hall da Fama']);
}
