<?php
/**
 * Migration: Adiciona ROY ao histórico e aplica correções
 * - Adiciona roy_player e roy_team_id na tabela season_history
 * - Adiciona colunas de draft order salvas na seasons
 */

require_once __DIR__ . '/backend/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h1>Migration: ROY e Correções</h1>";

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. Adicionar colunas ROY na season_history
    echo "<h2>1. Adicionando colunas ROY na season_history...</h2>";
    
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM season_history LIKE 'roy_player'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE season_history ADD COLUMN roy_player VARCHAR(100) AFTER sixth_man_team_id");
            $pdo->exec("ALTER TABLE season_history ADD COLUMN roy_team_id INT AFTER roy_player");
            echo "<p style='color:green'>✓ Colunas ROY adicionadas!</p>";
        } else {
            echo "<p style='color:orange'>⚠ Colunas ROY já existem</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Erro ao adicionar ROY: " . $e->getMessage() . "</p>";
    }
    
    // 2. Adicionar coluna para salvar draft_order na seasons
    echo "<h2>2. Adicionando coluna draft_order_snapshot na seasons...</h2>";
    
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM seasons LIKE 'draft_order_snapshot'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE seasons ADD COLUMN draft_order_snapshot JSON AFTER status");
            echo "<p style='color:green'>✓ Coluna draft_order_snapshot adicionada!</p>";
        } else {
            echo "<p style='color:orange'>⚠ Coluna draft_order_snapshot já existe</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Erro: " . $e->getMessage() . "</p>";
    }
    
    // 3. Adicionar coluna can_cancel_waiver na free_agents (para aposentadorias)
    echo "<h2>3. Verificando tabela free_agents...</h2>";
    
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'free_agents'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->query("SHOW COLUMNS FROM free_agents LIKE 'is_retirement'");
            if ($stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE free_agents ADD COLUMN is_retirement TINYINT(1) DEFAULT 0 COMMENT 'Se foi dispensa por aposentadoria'");
                echo "<p style='color:green'>✓ Coluna is_retirement adicionada!</p>";
            } else {
                echo "<p style='color:orange'>⚠ Coluna is_retirement já existe</p>";
            }
        } else {
            echo "<p style='color:orange'>⚠ Tabela free_agents não existe</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Erro: " . $e->getMessage() . "</p>";
    }
    
    // 4. Garantir coluna ranking_points na teams
    echo "<h2>4. Verificando coluna ranking_points na teams...</h2>";
    
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM teams LIKE 'ranking_points'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN ranking_points INT DEFAULT 0");
            echo "<p style='color:green'>✓ Coluna ranking_points adicionada!</p>";
        } else {
            echo "<p style='color:orange'>⚠ Coluna ranking_points já existe</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Erro: " . $e->getMessage() . "</p>";
    }
    
    echo "<h2 style='color:green'>✓ Migration concluída!</h2>";
    echo "<p><a href='/temporadas.php'>Ir para Temporadas</a></p>";
    
} catch (Exception $e) {
    echo "<h2 style='color:red'>Erro Fatal: " . $e->getMessage() . "</h2>";
}
