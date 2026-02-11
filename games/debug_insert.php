<?php
/**
 * DEBUG_INSERT.PHP - Debug específico do INSERT
 */

session_start();

require 'core/conexao.php';
require 'core/avatar.php';

$_SESSION['user_id'] = 1;
$user_id = 1;

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Debug INSERT</title>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #00ff00; padding: 20px; }
        pre { background: #000; padding: 10px; border: 1px solid #00ff00; overflow: auto; }
        .ok { color: #00ff00; }
        .erro { color: #ff0000; }
        .info { color: #ffff00; }
    </style>
</head>
<body>
<h1>🔍 Debug de INSERT</h1>
";

// Passo 1: Verificar tabela
echo "<h2>1. Verificando tabela usuario_inventario...</h2>";
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'usuario_inventario'");
    if ($stmt->fetch()) {
        echo "<p class='ok'>✅ Tabela existe</p>";
        
        // Mostrar estrutura
        echo "<h3>Estrutura da tabela:</h3>";
        $stmt = $pdo->query("DESCRIBE usuario_inventario");
        echo "<pre>";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "{$row['Field']} - {$row['Type']} - {$row['Null']} - {$row['Default']}\n";
        }
        echo "</pre>";
    } else {
        echo "<p class='erro'>❌ Tabela NÃO existe</p>";
        echo "<p class='info'>Execute criar_tabelas.php primeiro</p>";
        exit;
    }
} catch (Exception $e) {
    echo "<p class='erro'>❌ Erro: " . $e->getMessage() . "</p>";
    exit;
}

// Passo 2: Testar INSERT simples
echo "<h2>2. Testando INSERT simples...</h2>";
try {
    $sql = "INSERT INTO usuario_inventario (user_id, categoria, item_id, nome_item, raridade, data_obtencao) VALUES (?, ?, ?, ?, ?, NOW())";
    
    echo "<p>SQL: <code>$sql</code></p>";
    
    $stmt = $pdo->prepare($sql);
    $resultado = $stmt->execute([
        1,                          // user_id
        'colors',                   // categoria
        'neon_blue',               // item_id
        'Azul Neon',               // nome_item
        'common'                   // raridade
    ]);
    
    if ($resultado) {
        echo "<p class='ok'>✅ INSERT bem-sucedido!</p>";
        $id = $pdo->lastInsertId();
        echo "<p>ID inserido: $id</p>";
    } else {
        echo "<p class='erro'>❌ INSERT falhou</p>";
        $erro = $stmt->errorInfo();
        echo "<pre>" . json_encode($erro, JSON_PRETTY_PRINT) . "</pre>";
    }
} catch (Exception $e) {
    echo "<p class='erro'>❌ Exceção: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// Passo 3: Verificar dados inseridos
echo "<h2>3. Verificando dados no inventário...</h2>";
try {
    $stmt = $pdo->prepare("SELECT * FROM usuario_inventario WHERE user_id = ?");
    $stmt->execute([1]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Total de itens do usuário 1: " . count($itens) . "</p>";
    
    if (count($itens) > 0) {
        echo "<h3>Últimos itens:</h3>";
        echo "<pre>";
        foreach (array_slice($itens, -5) as $item) {
            echo json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            echo "\n---\n";
        }
        echo "</pre>";
    }
} catch (Exception $e) {
    echo "<p class='erro'>❌ Erro: " . $e->getMessage() . "</p>";
}

// Passo 4: Testar com named parameters
echo "<h2>4. Testando com named parameters (como abrirLootBox)...</h2>";
try {
    $sql = "INSERT INTO usuario_inventario (user_id, categoria, item_id, nome_item, raridade, data_obtencao) VALUES (:uid, :cat, :iid, :nome, :rar, NOW())";
    
    $stmt = $pdo->prepare($sql);
    $resultado = $stmt->execute([
        ':uid' => 1,
        ':cat' => 'hardware',
        ':iid' => 'pixel_crown',
        ':nome' => 'Coroa de Pixel',
        ':rar' => 'rare'
    ]);
    
    if ($resultado) {
        echo "<p class='ok'>✅ INSERT com named parameters bem-sucedido!</p>";
    } else {
        echo "<p class='erro'>❌ INSERT falhou</p>";
        $erro = $stmt->errorInfo();
        echo "<pre>" . json_encode($erro, JSON_PRETTY_PRINT) . "</pre>";
    }
} catch (Exception $e) {
    echo "<p class='erro'>❌ Exceção: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<h2>✅ Debug concluído</h2>";
echo "<p>Agora tente novamente: <a href='test_conexao.php'>test_conexao.php</a></p>";

echo "</body></html>";
?>
