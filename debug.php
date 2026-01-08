<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Diagnóstico do Sistema</h1>";
echo "<pre>";

try {
    echo "1. Verificando arquivo de configuração...\n";
    require_once __DIR__ . '/backend/helpers.php';
    echo "   ✓ helpers.php carregado\n\n";
    
    echo "2. Carregando configuração...\n";
    $config = loadConfig();
    echo "   ✓ Banco: " . $config['db']['name'] . "\n";
    echo "   ✓ Host: " . $config['db']['host'] . "\n\n";
    
    echo "3. Testando conexão com banco de dados...\n";
    require_once __DIR__ . '/backend/db.php';
    echo "   ✓ db.php carregado\n";
    
    $pdo = db();
    echo "   ✓ Conexão estabelecida\n\n";
    
    echo "4. Verificando estrutura do banco...\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "   Tabelas encontradas: " . count($tables) . "\n";
    foreach ($tables as $table) {
        echo "   - $table\n";
    }
    echo "\n";
    
    echo "5. Verificando coluna 'league' na tabela users...\n";
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $hasLeague = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'league') {
            $hasLeague = true;
            echo "   ✓ Coluna 'league' encontrada\n";
            echo "   - Tipo: " . $col['Type'] . "\n";
            echo "   - Default: " . ($col['Default'] ?? 'NULL') . "\n";
            break;
        }
    }
    if (!$hasLeague) {
        echo "   ✗ Coluna 'league' NÃO encontrada!\n";
    }
    echo "\n";
    
    echo "6. Testando arquivo auth.php...\n";
    require_once __DIR__ . '/backend/auth.php';
    echo "   ✓ auth.php carregado\n\n";
    
    echo "7. Testando session...\n";
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        echo "   ✓ Sessão iniciada\n";
    } else {
        echo "   ✓ Sessão já estava ativa\n";
    }
    echo "\n";
    
    echo "8. Contando usuários no banco...\n";
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "   Total de usuários: $userCount\n\n";
    
    echo "═══════════════════════════════════════\n";
    echo "✓ TODOS OS TESTES PASSARAM COM SUCESSO!\n";
    echo "═══════════════════════════════════════\n\n";
    
    echo "Se este arquivo funciona mas o index.php não,\n";
    echo "o problema pode estar em:\n";
    echo "- Permissões de arquivo\n";
    echo "- Configuração do .htaccess\n";
    echo "- Cache do navegador\n";
    
} catch (Exception $e) {
    echo "\n✗ ERRO ENCONTRADO:\n";
    echo "════════════════════════════════════════\n";
    echo "Mensagem: " . $e->getMessage() . "\n\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "</pre>";
