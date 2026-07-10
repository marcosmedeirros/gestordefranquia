<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$__migrateUser = getUserSession();
if (!$__migrateUser || $__migrateUser['user_type'] !== 'admin') {
    http_response_code(403);
    exit('Acesso negado.');
}

$pdo = db();

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

try {
    if (!columnExists($pdo, 'teams', 'conference')) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN conference ENUM('LESTE','OESTE') NULL AFTER league");
        $pdo->exec("ALTER TABLE teams ADD INDEX idx_team_conference (conference)");
        echo "[OK] Coluna 'conference' adicionada em 'teams'.\n";
    } else {
        echo "[SKIP] Coluna 'conference' já existe em 'teams'.\n";
    }
    echo "Migração concluída.";
} catch (Throwable $e) {
    error_log('Erro na migração conference: ' . $e->getMessage());
    http_response_code(500);
    echo "Erro na migração.";
}
