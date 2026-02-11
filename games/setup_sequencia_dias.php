<?php
/**
 * SETUP SEQUENCIA DE DIAS
 * Cria tabelas para rastrear sequências de dias nos jogos
 */

require 'core/conexao.php';

try {
    // Tabela para rastrear sequências de dias
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuario_sequencias_dias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        jogo VARCHAR(50) NOT NULL,
        sequencia_atual INT DEFAULT 0,
        ultima_jogada DATE,
        data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
    )");
    
    echo "✅ Tabela usuario_sequencias_dias criada com sucesso!\n";
    
} catch (PDOException $e) {
    echo "❌ Erro ao criar tabela: " . $e->getMessage() . "\n";
}

?>
