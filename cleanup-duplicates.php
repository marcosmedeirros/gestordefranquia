<?php
/**
 * Script de limpeza rápida de times duplicados
 * Execute uma vez para remover duplicatas
 */

require_once __DIR__ . '/backend/db.php';

$pdo = db();

echo "=== Limpeza de Times Duplicados ===\n\n";

// Verificar duplicatas por liga
$stmt = $pdo->prepare('
    SELECT league, user_id, name, COUNT(*) as cnt, GROUP_CONCAT(id) as ids
    FROM teams
    GROUP BY league, user_id, name
    HAVING cnt > 1
    ORDER BY league, user_id, name
');
$stmt->execute();
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicates)) {
    echo "✓ Nenhuma duplicata encontrada!\n";
    exit(0);
}

echo "Duplicatas encontradas:\n";
foreach ($duplicates as $dup) {
    $ids = array_map('intval', explode(',', $dup['ids']));
    echo "  - {$dup['league']} | User {$dup['user_id']} | {$dup['name']} | IDs: " . implode(', ', $ids) . "\n";
}

echo "\nProcessando remoção...\n";

$removed = 0;
foreach ($duplicates as $dup) {
    $ids = array_map('intval', explode(',', $dup['ids']));
    $keepId = $ids[0]; // Manter o primeiro
    
    foreach (array_slice($ids, 1) as $removeId) {
        // Mover jogadores para o time mantido
        $moveStmt = $pdo->prepare('UPDATE players SET team_id = ? WHERE team_id = ?');
        $moveStmt->execute([$keepId, $removeId]);
        $moved = $moveStmt->rowCount();
        
        // Deletar o time duplicado
        $delStmt = $pdo->prepare('DELETE FROM teams WHERE id = ?');
        $delStmt->execute([$removeId]);
        
        echo "  ✓ Removido time ID {$removeId} ({$moved} jogadores movidos para ID {$keepId})\n";
        $removed++;
    }
}

echo "\n✓ Total de {$removed} times duplicados removidos!\n";
echo "\nVocê pode agora acessar /teams.php normalmente.\n";
?>
