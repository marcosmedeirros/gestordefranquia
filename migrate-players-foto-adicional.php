<?php
require_once __DIR__ . '/backend/db.php';

$pdo = db();

echo "=== Migracao de Coluna Foto Adicional (players) ===\n\n";

try {
    $checkCol = $pdo->query("SHOW COLUMNS FROM players LIKE 'foto_adicional'");
    if ($checkCol->rowCount() === 0) {
        echo "Adicionando coluna foto_adicional...\n";
        $pdo->exec("ALTER TABLE players ADD COLUMN foto_adicional VARCHAR(255) NULL AFTER nba_player_id");
        echo "OK Coluna foto_adicional adicionada.\n";
    } else {
        echo "OK Coluna foto_adicional ja existe.\n";
    }
} catch (Exception $e) {
    echo "ERRO ao adicionar foto_adicional: " . $e->getMessage() . "\n";
}

echo "\n=== Migracao concluida ===\n";
