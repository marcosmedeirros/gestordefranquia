<?php
/**
 * TEST_LOOT.PHP - Debug das Loot Boxes
 */

session_start();

// Simula um user_id para testes
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1; // user_id padrão para teste
}

require 'core/conexao.php';
require 'core/avatar.php';

$user_id = $_SESSION['user_id'];

// Debug initial
echo "<h1>Teste de Loot Boxes - Debug</h1>";
echo "<pre>";
echo "User ID: " . $user_id . "\n";
echo "Session: " . json_encode($_SESSION) . "\n\n";

// Verificar conexão
try {
    $stmt = $pdo->prepare("SELECT id, nome, pontos FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Usuário encontrado: " . json_encode($usuario) . "\n\n";
} catch (PDOException $e) {
    echo "ERRO ao buscar usuário: " . $e->getMessage() . "\n";
    exit;
}

// Verificar tabelas
echo "Verificando estrutura de tabelas...\n";
$tables = ['usuarios', 'usuario_avatars', 'usuario_inventario'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->fetch();
        echo "Tabela $table: " . ($exists ? "✓ Existe" : "✗ Não existe") . "\n";
    } catch (PDOException $e) {
        echo "Erro ao verificar $table: " . $e->getMessage() . "\n";
    }
}

echo "\n--- Testando função abrirLootBox ---\n";

// Teste 1: Verificar saldo
echo "Saldo atual: " . $usuario['pontos'] . " pts\n";

// Teste 2: Tentar abrir caixa básica (20 pts)
echo "Tentando abrir caixa 'basica' (custo: 20 pts)...\n";
$resultado = abrirLootBox($pdo, $user_id, 'basica');
echo "Resultado: " . json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// Teste 3: Verificar inventário
echo "\n--- Inventário do usuário ---\n";
$inventario = obterInventario($pdo, $user_id);
echo "Total de itens: " . count($inventario) . "\n";
if (count($inventario) > 0) {
    echo "Últimos 5 itens:\n";
    foreach (array_slice($inventario, 0, 5) as $item) {
        echo json_encode($item) . "\n";
    }
}

// Teste 4: Verificar avatar
echo "\n--- Avatar do usuário ---\n";
$avatar = obterCustomizacaoAvatar($pdo, $user_id);
echo json_encode($avatar, JSON_PRETTY_PRINT) . "\n";

echo "</pre>";

?>
