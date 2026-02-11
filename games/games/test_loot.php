<?php
/**
 * GAMES/TEST_LOOT.PHP - Debug das Loot Boxes com Interface
 */

session_start();

require '../core/conexao.php';
require '../core/avatar.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Testa a função abrirLootBox
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['teste']) && $_POST['teste'] === 'abrir') {
    header('Content-Type: application/json; charset=utf-8');
    $tipo = $_POST['tipo'] ?? 'basica';
    
    error_log("=== TESTE LOOT BOX ===");
    error_log("User ID: $user_id");
    error_log("Tipo: $tipo");
    
    $resultado = abrirLootBox($pdo, $user_id, $tipo);
    
    error_log("Resultado: " . json_encode($resultado));
    
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Buscar dados do usuário
try {
    $stmt = $pdo->prepare("SELECT id, nome, pontos FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $usuario = null;
}

// Buscar inventário
$inventario = obterInventario($pdo, $user_id);

// Buscar avatar
$avatar = obterCustomizacaoAvatar($pdo, $user_id);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Debug Loot Boxes</title>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #00ff00; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .section { background: #222; padding: 15px; margin: 10px 0; border-left: 3px solid #00ff00; }
        .section h2 { margin: 0 0 10px 0; color: #00ff00; }
        .section p { margin: 5px 0; }
        .good { color: #00ff00; }
        .bad { color: #ff0000; }
        .warn { color: #ffff00; }
        button { background: #00ff00; color: #000; border: none; padding: 10px 20px; cursor: pointer; font-weight: bold; margin: 5px; }
        button:hover { background: #00dd00; }
        pre { background: #000; padding: 10px; overflow-x: auto; border: 1px solid #00ff00; }
        .status { padding: 10px; margin: 5px 0; border: 1px solid #00ff00; }
        .success { background: #003300; }
        .error { background: #330000; color: #ff6666; }
        .warning { background: #333300; color: #ffff99; }
    </style>
</head>
<body>

<div class="container">
    <h1>🎁 Debug Loot Boxes System</h1>
    
    <div class="section">
        <h2>👤 Informações do Usuário</h2>
        <?php if ($usuario): ?>
            <p><span class="good">✓</span> ID: <?= htmlspecialchars($usuario['id']) ?></p>
            <p><span class="good">✓</span> Nome: <?= htmlspecialchars($usuario['nome']) ?></p>
            <p><span class="good">✓</span> Pontos: <?= number_format($usuario['pontos'], 0, ',', '.') ?></p>
        <?php else: ?>
            <p><span class="bad">✗ Usuário não encontrado (ID: <?= $user_id ?>)</span></p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>💾 Status do Banco de Dados</h2>
        <?php
        $tables = ['usuarios', 'usuario_avatars', 'usuario_inventario'];
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM $table");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                echo "<p><span class='good'>✓</span> <strong>$table</strong>: {$result['cnt']} registros</p>";
            } catch (Exception $e) {
                echo "<p><span class='bad'>✗</span> <strong>$table</strong>: Erro - " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
        ?>
    </div>

    <div class="section">
        <h2>🎮 Avatar Atual</h2>
        <pre><?php echo json_encode($avatar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></pre>
    </div>

    <div class="section">
        <h2>📦 Inventário (<?= count($inventario) ?> itens)</h2>
        <?php if (count($inventario) > 0): ?>
            <pre><?php echo json_encode(array_slice($inventario, 0, 10), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></pre>
            <?php if (count($inventario) > 10): ?>
                <p><span class="warn">⚠</span> ... e mais <?= count($inventario) - 10 ?> itens</p>
            <?php endif; ?>
        <?php else: ?>
            <p><span class="warn">⚠</span> Inventário vazio</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>🧪 Testes Rápidos</h2>
        <p>Saldo atual: <strong><?= $usuario['pontos'] ?? '?' ?></strong> pts</p>
        
        <form method="POST" style="display: inline;">
            <input type="hidden" name="teste" value="abrir">
            <input type="hidden" name="tipo" value="basica">
            <button onclick="return testLootBox(this)">Testar Caixa Bolicheiro (20 pts)</button>
        </form>

        <form method="POST" style="display: inline;">
            <input type="hidden" name="teste" value="abrir">
            <input type="hidden" name="tipo" value="top">
            <button onclick="return testLootBox(this)">Testar Caixa Pnip (30 pts)</button>
        </form>

        <form method="POST" style="display: inline;">
            <input type="hidden" name="teste" value="abrir">
            <input type="hidden" name="tipo" value="premium">
            <button onclick="return testLootBox(this)">Testar Caixa PDSA (40 pts)</button>
        </form>

        <div id="resultado" style="margin-top: 20px;"></div>
    </div>

    <div class="section">
        <h2>📊 Configuração das Caixas</h2>
        <pre><?php 
            global $LOOT_BOXES;
            echo json_encode($LOOT_BOXES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); 
        ?></pre>
    </div>

    <div class="section">
        <h2>📋 Log de Erros</h2>
        <?php
        $logFile = __DIR__ . '/../logs/loot_boxes.log';
        if (file_exists($logFile)) {
            $lines = array_slice(explode("\n", file_get_contents($logFile)), -20);
            echo "<pre>" . htmlspecialchars(implode("\n", $lines)) . "</pre>";
        } else {
            echo "<p><span class='warn'>⚠</span> Nenhum log encontrado em: $logFile</p>";
        }
        ?>
    </div>
</div>

<script>
async function testLootBox(btn) {
    btn.disabled = true;
    btn.textContent = '⏳ Aguardando...';
    
    const form = btn.parentElement;
    const formData = new FormData(form);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const text = await response.text();
        console.log('Resposta bruta:', text);
        
        const data = JSON.parse(text);
        
        let html = '<div class="status ' + (data.sucesso ? 'success' : 'error') + '">';
        html += '<h3>' + (data.sucesso ? '✅ Sucesso!' : '❌ Erro!') + '</h3>';
        html += '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
        html += '</div>';
        
        document.getElementById('resultado').innerHTML = html;
        
        // Recarregar página após sucesso
        if (data.sucesso) {
            setTimeout(() => location.reload(), 2000);
        }
    } catch (e) {
        console.error('Erro:', e);
        document.getElementById('resultado').innerHTML = '<div class="status error"><h3>❌ Erro na Requisição</h3><p>' + e.message + '</p></div>';
    }
    
    btn.disabled = false;
    btn.textContent = btn.getAttribute('data-original') || 'Testar';
    return false;
}
</script>

</body>
</html>
