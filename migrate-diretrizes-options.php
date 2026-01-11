<?php
/**
 * Migração para atualizar o formulário de diretrizes com novas opções
 * Execute este script para atualizar a estrutura da tabela team_directives
 */

require_once __DIR__ . '/backend/db.php';

$pdo = db();

echo "=== Migração: Atualização do Formulário de Diretrizes ===\n\n";

try {
    $pdo->beginTransaction();
    
    // 1. Verificar se as novas colunas já existem
    $stmt = $pdo->query("SHOW COLUMNS FROM team_directives");
    $existingColumns = array_column($stmt->fetchAll(), 'Field');
    
    // 2. Adicionar novas colunas se não existirem
    if (!in_array('rotation_players', $existingColumns)) {
        echo "Adicionando coluna rotation_players...\n";
        $pdo->exec("ALTER TABLE team_directives ADD COLUMN rotation_players INT DEFAULT 10 COMMENT 'Jogadores na rotação (8-15)'");
    }
    
    if (!in_array('veteran_focus', $existingColumns)) {
        echo "Adicionando coluna veteran_focus...\n";
        $pdo->exec("ALTER TABLE team_directives ADD COLUMN veteran_focus INT DEFAULT 50 COMMENT 'Foco em jogadores veteranos (0-100%)'");
    }
    
    if (!in_array('gleague_1_id', $existingColumns)) {
        echo "Adicionando coluna gleague_1_id...\n";
        $pdo->exec("ALTER TABLE team_directives ADD COLUMN gleague_1_id INT NULL COMMENT 'Jogador 1 a mandar para G-League'");
    }
    
    if (!in_array('gleague_2_id', $existingColumns)) {
        echo "Adicionando coluna gleague_2_id...\n";
        $pdo->exec("ALTER TABLE team_directives ADD COLUMN gleague_2_id INT NULL COMMENT 'Jogador 2 a mandar para G-League'");
    }
    
    // 3. Modificar colunas existentes para ENUM com novos valores
    // Primeiro, vamos converter valores antigos para compatíveis
    echo "Atualizando valores antigos para compatibilidade...\n";
    
    // pace: era INT, agora é ENUM
    // Se pace era numérico, vamos converter para no_preference temporariamente
    $pdo->exec("UPDATE team_directives SET pace = 'no_preference' WHERE pace IS NULL OR pace NOT IN ('no_preference', 'patient', 'average', 'shoot_at_will')");
    
    // offensive_rebound: era INT, agora é ENUM
    $pdo->exec("UPDATE team_directives SET offensive_rebound = 'no_preference' WHERE offensive_rebound IS NULL OR offensive_rebound NOT IN ('limit_transition', 'no_preference', 'crash_glass', 'some_crash')");
    
    // offensive_aggression: era INT, agora é ENUM (usado para agressividade defensiva)
    $pdo->exec("UPDATE team_directives SET offensive_aggression = 'no_preference' WHERE offensive_aggression IS NULL OR offensive_aggression NOT IN ('physical', 'no_preference', 'conservative', 'neutral')");
    
    // defensive_rebound: era INT, agora é ENUM
    $pdo->exec("UPDATE team_directives SET defensive_rebound = 'no_preference' WHERE defensive_rebound IS NULL OR defensive_rebound NOT IN ('run_transition', 'crash_glass', 'some_crash', 'no_preference')");
    
    // rotation_style: era balanced/short/deep, agora é manual/auto
    $pdo->exec("UPDATE team_directives SET rotation_style = 'auto' WHERE rotation_style NOT IN ('manual', 'auto')");
    
    // game_style: era fast/balanced/slow, agora tem mais opções
    $pdo->exec("UPDATE team_directives SET game_style = 'balanced' WHERE game_style NOT IN ('balanced', 'triangle', 'grit_grind', 'pace_space', 'perimeter_centric', 'post_centric', 'seven_seconds', 'defense', 'franchise_player', 'most_stars')");
    
    // offense_style: era inside/balanced/outside, agora tem mais opções
    $pdo->exec("UPDATE team_directives SET offense_style = 'no_preference' WHERE offense_style NOT IN ('no_preference', 'pick_roll', 'neutral', 'play_through_star', 'get_to_basket', 'get_shooters_open', 'feed_post')");
    
    echo "Modificando estrutura das colunas...\n";
    
    // 4. Modificar as colunas para os novos tipos ENUM
    // Nota: Fazemos em try/catch separados pois pode falhar se já estiver no formato correto
    
    try {
        $pdo->exec("ALTER TABLE team_directives MODIFY COLUMN pace VARCHAR(50) DEFAULT 'no_preference' COMMENT 'Tempo de ataque'");
    } catch (Exception $e) {
        echo "Nota: pace - " . $e->getMessage() . "\n";
    }
    
    try {
        $pdo->exec("ALTER TABLE team_directives MODIFY COLUMN offensive_rebound VARCHAR(50) DEFAULT 'no_preference' COMMENT 'Rebote ofensivo'");
    } catch (Exception $e) {
        echo "Nota: offensive_rebound - " . $e->getMessage() . "\n";
    }
    
    try {
        $pdo->exec("ALTER TABLE team_directives MODIFY COLUMN offensive_aggression VARCHAR(50) DEFAULT 'no_preference' COMMENT 'Agressividade defensiva'");
    } catch (Exception $e) {
        echo "Nota: offensive_aggression - " . $e->getMessage() . "\n";
    }
    
    try {
        $pdo->exec("ALTER TABLE team_directives MODIFY COLUMN defensive_rebound VARCHAR(50) DEFAULT 'no_preference' COMMENT 'Rebote defensivo'");
    } catch (Exception $e) {
        echo "Nota: defensive_rebound - " . $e->getMessage() . "\n";
    }
    
    try {
        $pdo->exec("ALTER TABLE team_directives MODIFY COLUMN rotation_style VARCHAR(50) DEFAULT 'auto' COMMENT 'Estilo de rotação'");
    } catch (Exception $e) {
        echo "Nota: rotation_style - " . $e->getMessage() . "\n";
    }
    
    try {
        $pdo->exec("ALTER TABLE team_directives MODIFY COLUMN game_style VARCHAR(50) DEFAULT 'balanced' COMMENT 'Estilo de jogo'");
    } catch (Exception $e) {
        echo "Nota: game_style - " . $e->getMessage() . "\n";
    }
    
    try {
        $pdo->exec("ALTER TABLE team_directives MODIFY COLUMN offense_style VARCHAR(50) DEFAULT 'no_preference' COMMENT 'Estilo de ataque'");
    } catch (Exception $e) {
        echo "Nota: offense_style - " . $e->getMessage() . "\n";
    }
    
    // 5. Remover coluna defense_style se existir
    if (in_array('defense_style', $existingColumns)) {
        echo "Removendo coluna defense_style (não usada mais)...\n";
        try {
            $pdo->exec("ALTER TABLE team_directives DROP COLUMN defense_style");
        } catch (Exception $e) {
            echo "Nota: defense_style - " . $e->getMessage() . "\n";
        }
    }
    
    $pdo->commit();
    
    echo "\n=== Migração concluída com sucesso! ===\n";
    echo "O formulário de diretrizes foi atualizado com as novas opções.\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
