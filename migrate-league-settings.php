<?php
require_once __DIR__ . '/backend/db.php';
$pdo = db();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS league_settings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      league ENUM('ELITE','PRIME','RISE','ROOKIE') NOT NULL UNIQUE,
      cap_min INT NOT NULL DEFAULT 0,
      cap_max INT NOT NULL DEFAULT 0,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM league_settings");
    $exists = (int)($stmt->fetch()['cnt'] ?? 0);
    if ($exists === 0) {
        $pdo->exec("INSERT INTO league_settings (league, cap_min, cap_max) VALUES
            ('ELITE',618,648),('PRIME',618,648),('RISE',618,648),('ROOKIE',618,648)");
    }
    echo "✅ Tabela league_settings pronta.";
} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage();
}
