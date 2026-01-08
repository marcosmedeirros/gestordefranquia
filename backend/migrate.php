<?php
/**
 * Script de migração para adicionar suporte a múltiplas ligas
 * Execute este arquivo acessando: https://marcosmedeiros.page/backend/migrate.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = db();
    $config = loadConfig();
    $dbName = $config['db']['name'];
    
    $migrations = [];
    
    // 1. Criar tabela leagues se não existir
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS leagues (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name ENUM('ELITE', 'PRIME', 'RISE', 'ROOKIE') NOT NULL UNIQUE,
                description VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $migrations[] = "✓ Tabela 'leagues' verificada/criada";
    } catch (PDOException $e) {
        $migrations[] = "⚠ Tabela 'leagues': " . $e->getMessage();
    }
    
    // 2. Inserir ligas padrão
    try {
        $pdo->exec("
            INSERT IGNORE INTO leagues (name, description) VALUES
            ('ELITE', 'Liga Elite - Nível mais alto'),
            ('PRIME', 'Liga Prime - Nível intermediário superior'),
            ('RISE', 'Liga Rise - Nível intermediário'),
            ('ROOKIE', 'Liga Rookie - Nível inicial');
        ");
        $migrations[] = "✓ Ligas padrão inseridas";
    } catch (PDOException $e) {
        $migrations[] = "⚠ Inserção de ligas: " . $e->getMessage();
    }
    
    // 3. Adicionar coluna league à tabela users
    $checkUsersLeague = $pdo->prepare("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'league'
    ");
    $checkUsersLeague->execute([$dbName]);
    
    if (!$checkUsersLeague->fetch()) {
        try {
            $pdo->exec("
                ALTER TABLE users 
                ADD COLUMN league ENUM('ELITE', 'PRIME', 'RISE', 'ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER user_type,
                ADD INDEX idx_users_league (league);
            ");
            $migrations[] = "✓ Coluna 'league' adicionada à tabela 'users'";
        } catch (PDOException $e) {
            $migrations[] = "✗ Erro ao adicionar coluna league em users: " . $e->getMessage();
        }
    } else {
        $migrations[] = "✓ Coluna 'league' já existe na tabela 'users'";
    }
    
    // 4. Adicionar coluna league à tabela teams
    $checkTeamsLeague = $pdo->prepare("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'teams' AND COLUMN_NAME = 'league'
    ");
    $checkTeamsLeague->execute([$dbName]);
    
    if (!$checkTeamsLeague->fetch()) {
        try {
            $pdo->exec("
                ALTER TABLE teams 
                ADD COLUMN league ENUM('ELITE', 'PRIME', 'RISE', 'ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER user_id,
                ADD INDEX idx_teams_league (league);
            ");
            $migrations[] = "✓ Coluna 'league' adicionada à tabela 'teams'";
        } catch (PDOException $e) {
            $migrations[] = "✗ Erro ao adicionar coluna league em teams: " . $e->getMessage();
        }
    } else {
        $migrations[] = "✓ Coluna 'league' já existe na tabela 'teams'";
    }
    
    // 5. Adicionar coluna league à tabela divisions
    $checkDivisionsLeague = $pdo->prepare("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'divisions' AND COLUMN_NAME = 'league'
    ");
    $checkDivisionsLeague->execute([$dbName]);
    
    if (!$checkDivisionsLeague->fetch()) {
        try {
            $pdo->exec("
                ALTER TABLE divisions 
                ADD COLUMN league ENUM('ELITE', 'PRIME', 'RISE', 'ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER name,
                ADD INDEX idx_divisions_league (league);
            ");
            $migrations[] = "✓ Coluna 'league' adicionada à tabela 'divisions'";
        } catch (PDOException $e) {
            $migrations[] = "✗ Erro ao adicionar coluna league em divisions: " . $e->getMessage();
        }
    } else {
        $migrations[] = "✓ Coluna 'league' já existe na tabela 'divisions'";
    }
    
    // 6. Adicionar coluna league à tabela drafts
    $checkDraftsLeague = $pdo->prepare("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'drafts' AND COLUMN_NAME = 'league'
    ");
    $checkDraftsLeague->execute([$dbName]);
    
    if (!$checkDraftsLeague->fetch()) {
        try {
            $pdo->exec("
                ALTER TABLE drafts 
                ADD COLUMN league ENUM('ELITE', 'PRIME', 'RISE', 'ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER year,
                ADD INDEX idx_drafts_league (league);
            ");
            $migrations[] = "✓ Coluna 'league' adicionada à tabela 'drafts'";
        } catch (PDOException $e) {
            $migrations[] = "✗ Erro ao adicionar coluna league em drafts: " . $e->getMessage();
        }
    } else {
        $migrations[] = "✓ Coluna 'league' já existe na tabela 'drafts'";
    }
    
    jsonResponse(200, [
        'success' => true,
        'message' => 'Migração concluída!',
        'migrations' => $migrations
    ]);
    
} catch (Exception $e) {
    jsonResponse(500, [
        'success' => false,
        'error' => 'Erro na migração: ' . $e->getMessage()
    ]);
}
