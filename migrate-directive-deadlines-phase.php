<?php
/**
 * Migração para garantir a coluna 'phase' em directive_deadlines
 * e criar a tabela de minutagem por jogador se não existir.
 */

require_once __DIR__ . '/backend/db.php';

$pdo = db();

echo "=== Migração: Atualização de directive_deadlines (phase) e minutos por jogador ===\n\n";

try {
    $pdo->beginTransaction();

    // Verificar colunas existentes em directive_deadlines
    $stmt = $pdo->query("SHOW COLUMNS FROM directive_deadlines");
    $existingColumns = array_column($stmt->fetchAll(), 'Field');

    // Adicionar coluna 'phase' se não existir
    if (!in_array('phase', $existingColumns)) {
        echo "Adicionando coluna 'phase' em directive_deadlines...\n";
        $pdo->exec("ALTER TABLE directive_deadlines ADD COLUMN phase ENUM('regular','playoffs') DEFAULT 'regular' AFTER description");
    } else {
        echo "Coluna 'phase' já existe.\n";
    }

    // Adicionar coluna 'is_active' se não existir (por segurança)
    if (!in_array('is_active', $existingColumns)) {
        echo "Adicionando coluna 'is_active' em directive_deadlines...\n";
        $pdo->exec("ALTER TABLE directive_deadlines ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER phase");
    } else {
        echo "Coluna 'is_active' já existe.\n";
    }

    // Criar tabela directive_player_minutes se não existir
    echo "Garantindo a criação da tabela 'directive_player_minutes'...\n";
    $sqlMinutes = file_get_contents(__DIR__ . '/sql/add_player_minutes.sql');
    $statements = array_filter(array_map('trim', explode(';', $sqlMinutes)));
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                // Ignorar erros de 'already exists'
                if (strpos(strtolower($e->getMessage()), 'already exists') === false) {
                    throw $e;
                }
            }
        }
    }

    $pdo->commit();
    echo "\n=== Migração concluída com sucesso! ===\n";
    echo "- directive_deadlines: coluna 'phase' e 'is_active' verificadas/adicionadas.\n";
    echo "- directive_player_minutes: tabela criada/confirmada.\n";
    echo "\n<a href='/' style='color: #f17507;'>Voltar ao Dashboard</a>\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\nERRO: " . $e->getMessage() . "\n";
    exit(1);
}
