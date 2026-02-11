<?php
/**
 * TEST_SEQUENCIA.PHP
 * Script para adicionar sequências de teste
 */

require 'core/conexao.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Adicionar Sequências de Teste</title>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #00ff00; padding: 20px; }
        .ok { color: #00ff00; }
        .erro { color: #ff0000; }
        pre { background: #000; padding: 10px; border: 1px solid #00ff00; }
    </style>
</head>
<body>
<h1>🧪 Adicionar Sequências de Teste</h1>
";

try {
    // Adicionar sequência de 1 dia para usuário 1 (Termo)
    $stmt = $pdo->prepare("
        INSERT INTO usuario_sequencias_dias (user_id, jogo, sequencia_atual, ultima_jogada) 
        VALUES (:uid, :jogo, :seq, :data)
        ON DUPLICATE KEY UPDATE 
            sequencia_atual = :seq, ultima_jogada = :data
    ");
    
    $hoje = date('Y-m-d');
    
    $stmt->execute([
        ':uid' => 1,
        ':jogo' => 'termo',
        ':seq' => 1,
        ':data' => $hoje
    ]);
    
    echo "<p class='ok'>✅ Usuário 1: Sequência de 1 dia no TERMO adicionada</p>";
    
    // Adicionar sequência para usuário 19 (Memória)
    $stmt->execute([
        ':uid' => 19,
        ':jogo' => 'memoria',
        ':seq' => 1,
        ':data' => $hoje
    ]);
    
    echo "<p class='ok'>✅ Usuário 19: Sequência de 1 dia na MEMÓRIA adicionada</p>";
    
    // Verificar dados
    $stmt = $pdo->query("SELECT user_id, jogo, sequencia_atual, ultima_jogada FROM usuario_sequencias_dias WHERE user_id IN (1, 19)");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Dados Inseridos:</h2>";
    echo "<pre>";
    foreach ($results as $row) {
        echo "User: {$row['user_id']}, Jogo: {$row['jogo']}, Sequência: {$row['sequencia_atual']}, Data: {$row['ultima_jogada']}\n";
    }
    echo "</pre>";
    
    echo "<p style='color: #ffff00;'>✅ Sequências adicionadas com sucesso!</p>";
    
} catch (PDOException $e) {
    echo "<p class='erro'>❌ Erro: " . $e->getMessage() . "</p>";
}

echo "</body></html>";

?>
