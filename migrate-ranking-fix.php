<?php
require_once __DIR__ . '/backend/db.php';
$pdo = db();

echo "<h2>Corrigindo tabela team_ranking_points...</h2>";

try {
    // 1. Criar tabela se não existir (estrutura completa)
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_ranking_points (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NOT NULL,
        season_id INT NOT NULL,
        points INT NOT NULL DEFAULT 0,
        reason VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    echo "✓ Tabela verificada.<br>";

    // 2. Verificar e adicionar colunas faltantes
    $columns = $pdo->query("SHOW COLUMNS FROM team_ranking_points")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('points', $columns)) {
        $pdo->exec("ALTER TABLE team_ranking_points ADD COLUMN points INT NOT NULL DEFAULT 0 AFTER season_id");
        echo "✓ Coluna 'points' adicionada.<br>";
    }
    
    if (!in_array('reason', $columns)) {
        $pdo->exec("ALTER TABLE team_ranking_points ADD COLUMN reason VARCHAR(255) AFTER points");
        echo "✓ Coluna 'reason' adicionada.<br>";
    }

    echo "<h3 style='color: green'>Sucesso! Tente salvar o histórico novamente.</h3>";

} catch (PDOException $e) {
    echo "<h3 style='color: red'>Erro: " . $e->getMessage() . "</h3>";
}