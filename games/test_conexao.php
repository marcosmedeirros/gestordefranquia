<?php
/**
 * TEST_CONEXAO.PHP - Teste rápido de conexão
 */

session_start();

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Teste Conexão</title>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #00ff00; padding: 20px; }
        pre { background: #000; padding: 10px; border: 1px solid #00ff00; overflow: auto; }
        .ok { color: #00ff00; }
        .erro { color: #ff0000; }
    </style>
</head>
<body>
<h1>🔌 Teste de Conexão</h1>
";

// Teste 1: Carregar conexão
echo "<h2>1. Carregando conexão...</h2>";
try {
    require 'core/conexao.php';
    echo "<p class='ok'>✅ Conexão carregada com sucesso</p>";
} catch (Exception $e) {
    echo "<p class='erro'>❌ Erro: " . $e->getMessage() . "</p>";
    exit;
}

// Teste 2: Carregar avatar.php
echo "<h2>2. Carregando core/avatar.php...</h2>";
try {
    require 'core/avatar.php';
    echo "<p class='ok'>✅ avatar.php carregado com sucesso</p>";
} catch (Exception $e) {
    echo "<p class='erro'>❌ Erro: " . $e->getMessage() . "</p>";
    exit;
}

// Teste 3: Verificar variáveis globais
echo "<h2>3. Verificando variáveis globais...</h2>";
echo "<p>LOOT_BOXES definido? " . (isset($LOOT_BOXES) ? "<span class='ok'>✅ SIM</span>" : "<span class='erro'>❌ NÃO</span>") . "</p>";
echo "<p>AVATAR_COMPONENTES definido? " . (isset($AVATAR_COMPONENTES) ? "<span class='ok'>✅ SIM</span>" : "<span class='erro'>❌ NÃO</span>") . "</p>";

if (isset($LOOT_BOXES)) {
    echo "<h3>Caixas disponíveis:</h3><pre>";
    foreach ($LOOT_BOXES as $k => $v) {
        echo "- $k: " . $v['nome'] . " (" . $v['preco'] . " pts)\n";
    }
    echo "</pre>";
}

// Teste 4: Testar conexão ao banco
echo "<h2>4. Testando banco de dados...</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM usuarios");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p class='ok'>✅ Tabela usuarios: " . $result['cnt'] . " usuários</p>";
} catch (Exception $e) {
    echo "<p class='erro'>❌ Erro: " . $e->getMessage() . "</p>";
}

// Teste 5: Verificar user_id padrão
echo "<h2>5. User padrão (ID: 1)...</h2>";
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

try {
    $stmt = $pdo->prepare("SELECT id, nome, pontos FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => 1]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "<p class='ok'>✅ Usuário encontrado</p>";
        echo "<pre>ID: " . $user['id'] . "\nNome: " . $user['nome'] . "\nPontos: " . $user['pontos'] . "</pre>";
    } else {
        echo "<p class='erro'>❌ Usuário ID 1 não encontrado</p>";
    }
} catch (Exception $e) {
    echo "<p class='erro'>❌ Erro: " . $e->getMessage() . "</p>";
}

// Teste 6: Testar função abrirLootBox
echo "<h2>6. Teste da função abrirLootBox...</h2>";
if (isset($LOOT_BOXES) && isset($pdo)) {
    $resultado = abrirLootBox($pdo, 1, 'basica');
    echo "<pre>";
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo "</pre>";
    
    if ($resultado['sucesso']) {
        echo "<p class='ok'>✅ Caixa aberta com sucesso!</p>";
    } else {
        echo "<p class='erro'>❌ Erro: " . $resultado['mensagem'] . "</p>";
    }
}

// Teste 7: Verificar logs
echo "<h2>7. Verificar arquivos de log...</h2>";
$logDir = __DIR__ . '/logs';
if (is_dir($logDir)) {
    echo "<p class='ok'>✅ Diretório /logs existe</p>";
    $files = glob($logDir . '/*.log');
    if (count($files) > 0) {
        echo "<p class='ok'>✅ Arquivos de log encontrados:</p>";
        foreach ($files as $file) {
            $size = filesize($file);
            echo "<p>  - " . basename($file) . " (" . $size . " bytes)</p>";
        }
    } else {
        echo "<p>⚠️ Nenhum arquivo de log encontrado ainda</p>";
    }
} else {
    echo "<p class='erro'>❌ Diretório /logs não existe</p>";
}

echo "</body></html>";
?>
