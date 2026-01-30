<?php
require_once __DIR__ . '/backend/db.php';

$pdo = db();

echo "Iniciando migração de contador de trades por time...\n";

try {
    $pdo->beginTransaction();

    $hasTradesUsed = $pdo->query("SHOW COLUMNS FROM teams LIKE 'trades_used'")->fetch();
    if (!$hasTradesUsed) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN trades_used INT NOT NULL DEFAULT 0 AFTER current_cycle");
        echo "✅ Coluna trades_used adicionada em teams\n";
    } else {
        echo "ℹ️ Coluna trades_used já existe\n";
    }

    $hasTradesCycle = $pdo->query("SHOW COLUMNS FROM teams LIKE 'trades_cycle'")->fetch();
    if (!$hasTradesCycle) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN trades_cycle INT NOT NULL DEFAULT 1 AFTER trades_used");
        echo "✅ Coluna trades_cycle adicionada em teams\n";
    } else {
        echo "ℹ️ Coluna trades_cycle já existe\n";
    }

    // Sincronizar trades_cycle com current_cycle quando disponível
    try {
        $pdo->exec("UPDATE teams SET trades_cycle = current_cycle WHERE trades_cycle IS NULL OR trades_cycle = 0");
        echo "✅ trades_cycle sincronizado com current_cycle\n";
    } catch (Exception $e) {
        echo "⚠️ Não foi possível sincronizar trades_cycle: " . $e->getMessage() . "\n";
    }

    $pdo->commit();
    echo "Migração concluída com sucesso!\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Erro na migração: " . $e->getMessage() . "\n";
    exit(1);
}
