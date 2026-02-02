<?php
require_once __DIR__ . '/backend/db.php';

try {
    $pdo = db();

    echo "Iniciando migração para adicionar controle de abertura/fechamento da Free Agency...\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS league_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL UNIQUE,
        cap_min INT NOT NULL DEFAULT 0,
        cap_max INT NOT NULL DEFAULT 0,
        max_trades INT NOT NULL DEFAULT 3,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("ALTER TABLE league_settings 
        ADD COLUMN IF NOT EXISTS fa_enabled TINYINT(1) DEFAULT 1 COMMENT 'Se 1, propostas na FA estão abertas; se 0, fechadas'
    ");

    echo "✅ Coluna fa_enabled adicionada à tabela league_settings\n";
    echo "✅ Por padrão, todas as ligas permanecem com propostas abertas (valor 1)\n";
    echo "\nMigração concluída com sucesso!\n";

} catch (PDOException $e) {
    echo "❌ Erro na migração: " . $e->getMessage() . "\n";
    exit(1);
}
