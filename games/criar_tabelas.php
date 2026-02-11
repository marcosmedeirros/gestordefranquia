<?php
/**
 * CRIAR_TABELAS.PHP - Script para criar tabelas necessárias
 */

require 'core/conexao.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Criar Tabelas</title>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #00ff00; padding: 20px; }
        pre { background: #000; padding: 10px; border: 1px solid #00ff00; overflow: auto; }
        .ok { color: #00ff00; }
        .erro { color: #ff0000; }
        .info { color: #ffff00; }
    </style>
</head>
<body>
<h1>📋 Criar Tabelas Necessárias</h1>
";

$tabelas = [
    'usuario_avatars' => "
        CREATE TABLE IF NOT EXISTS usuario_avatars (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            color VARCHAR(50) DEFAULT 'default',
            hardware VARCHAR(50) DEFAULT 'none',
            clothing VARCHAR(50) DEFAULT 'none',
            footwear VARCHAR(50) DEFAULT 'none',
            elite VARCHAR(50) DEFAULT 'none',
            aura VARCHAR(50) DEFAULT 'none',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
    'usuario_inventario' => "
        CREATE TABLE IF NOT EXISTS usuario_inventario (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            categoria VARCHAR(50) NOT NULL,
            item_id VARCHAR(100) NOT NULL,
            nome_item VARCHAR(150) NOT NULL,
            raridade VARCHAR(50) NOT NULL,
            data_obtencao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_user (user_id),
            INDEX idx_raridade (raridade)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
    'usuario_sequencias_dias' => "
        CREATE TABLE IF NOT EXISTS usuario_sequencias_dias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            jogo VARCHAR(50) NOT NULL,
            sequencia_atual INT DEFAULT 0,
            ultima_jogada DATE,
            data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_jogo (jogo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    "
];

foreach ($tabelas as $nome => $sql) {
    try {
        echo "<h2>Criando tabela: $nome</h2>";
        $pdo->exec($sql);
        
        // Verificar se foi criada
        $stmt = $pdo->query("SHOW TABLES LIKE '$nome'");
        if ($stmt->fetch()) {
            echo "<p class='ok'>✅ Tabela $nome criada/verificada com sucesso</p>";
            
            // Mostrar estrutura
            $stmt = $pdo->query("DESCRIBE $nome");
            echo "<pre>";
            echo "Colunas da tabela $nome:\n";
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo "  - {$row['Field']} ({$row['Type']})\n";
            }
            echo "</pre>";
        } else {
            echo "<p class='erro'>❌ Falha ao criar tabela $nome</p>";
        }
    } catch (Exception $e) {
        echo "<p class='erro'>❌ Erro: " . $e->getMessage() . "</p>";
    }
}

echo "<h2>✅ Concluído!</h2>";
echo "<p>Agora tente novamente: <a href='debug_avatar.php'>debug_avatar.php</a></p>";

echo "</body></html>";
?>
