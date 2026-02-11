<?php
/**
 * TEST RANKING AVATARS
 * Verifica se os avatares estão sendo exibidos corretamente nos rankings
 */

session_start();
require 'core/conexao.php';
require 'core/avatar.php';

// Se não está logado, logar como user 1 temporariamente
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

$user_id = $_SESSION['user_id'];

echo "<!DOCTYPE html>
<html lang=\"pt-br\" data-bs-theme=\"dark\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Teste de Avatares no Ranking</title>
    <link href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css\" rel=\"stylesheet\">
    <style>
        :root {
            --primary-dark: #121212;
            --secondary-dark: #1e1e1e;
            --border-dark: #333;
            --accent-green: #00e676;
        }
        body {
            background-color: var(--primary-dark);
            color: #e0e0e0;
        }
        .test-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
        }
        .test-section {
            background: var(--secondary-dark);
            border: 1px solid var(--border-dark);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .test-title {
            color: var(--accent-green);
            font-size: 1.5rem;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .ranking-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.95rem;
            gap: 10px;
        }
        .ranking-avatar {
            flex-shrink: 0;
            width: 48px;
            height: 67px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--accent-green);
            border-radius: 4px;
        }
        .ranking-avatar svg {
            width: 100%;
            height: 100%;
        }
        .ranking-name {
            flex: 1;
            margin: 0 10px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ranking-value {
            font-weight: 700;
            color: #fff;
            text-align: right;
        }
        .medal-1::before { content: '🥇'; margin-right: 5px; }
        .medal-2::before { content: '🥈'; margin-right: 5px; }
        .medal-3::before { content: '🥉'; margin-right: 5px; }
        .medal-4::before { content: '🏅'; margin-right: 5px; }
        .medal-5::before { content: '🏅'; margin-right: 5px; }
        .debug-info {
            background: #2b2b2b;
            border-left: 3px solid var(--accent-green);
            padding: 10px;
            margin: 10px 0;
            font-family: monospace;
            font-size: 0.85rem;
            color: #aed581;
            overflow-x: auto;
        }
    </style>
</head>
<body>
<div class=\"test-container\">
    <h1 style=\"color: var(--accent-green); margin-bottom: 30px;\">🧪 Teste de Avatares no Ranking</h1>";

try {
    // Top 5 Ranking Geral
    $stmt = $pdo->query("
        SELECT id, nome, pontos, (pontos - 50) as lucro_liquido 
        FROM usuarios 
        ORDER BY lucro_liquido DESC 
        LIMIT 5
    ");
    $top_5_ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<div class=\"test-section\">
        <div class=\"test-title\">📊 Top 5 Ranking Geral</div>";

    if (empty($top_5_ranking)) {
        echo "<p style=\"color: #999;\">Nenhum usuário encontrado</p>";
    } else {
        foreach ($top_5_ranking as $idx => $jogador) {
            $avatar_jogador = obterCustomizacaoAvatar($pdo, $jogador['id']);
            
            echo "<div class=\"ranking-item medal-" . ($idx+1) . "\">
                <span class=\"ranking-position\" aria-label=\"Posição " . ($idx+1) . "\"></span>
                <div class=\"ranking-avatar\">";
            
            // Debug
            echo renderizarAvatarSVG($avatar_jogador, 32);
            
            echo "</div>
                <span class=\"ranking-name\">" . htmlspecialchars($jogador['nome']) . "</span>
                <span class=\"ranking-value\">
                    " . number_format($jogador['lucro_liquido'], 0, ',', '.') . " pts
                </span>
            </div>";
            
            // Debug info
            echo "<div class=\"debug-info\">
                ID: {$jogador['id']} | 
                Avatar Config: " . json_encode($avatar_jogador) . "
            </div>";
        }
    }
    
    echo "</div>";

    // Top 5 Maiores Cafés
    $stmt = $pdo->query("
        SELECT id, nome, cafes_feitos 
        FROM usuarios 
        WHERE cafes_feitos > 0 
        ORDER BY cafes_feitos DESC 
        LIMIT 5
    ");
    $top_5_cafes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<div class=\"test-section\">
        <div class=\"test-title\">☕ Top Cafés</div>";

    if (empty($top_5_cafes)) {
        echo "<p style=\"color: #999;\">Nenhum usuário com cafés</p>";
    } else {
        foreach ($top_5_cafes as $idx => $jogador) {
            $avatar_jogador = obterCustomizacaoAvatar($pdo, $jogador['id']);
            
            echo "<div class=\"ranking-item medal-" . ($idx+1) . "\">
                <span class=\"ranking-position\" aria-label=\"Posição " . ($idx+1) . "\"></span>
                <div class=\"ranking-avatar\">";
            
            echo renderizarAvatarSVG($avatar_jogador, 32);
            
            echo "</div>
                <span class=\"ranking-name\">" . htmlspecialchars($jogador['nome']) . "</span>
                <span class=\"ranking-value\">
                    <i class=\"bi bi-cup-hot\"></i> " . $jogador['cafes_feitos'] . "
                </span>
            </div>";
            
            // Debug info
            echo "<div class=\"debug-info\">
                ID: {$jogador['id']} | 
                Avatar Config: " . json_encode($avatar_jogador) . "
            </div>";
        }
    }
    
    echo "</div>";

    // Test individual avatar rendering
    echo "<div class=\"test-section\">
        <div class=\"test-title\">🎨 Teste Individual de Avatar (ID 1)</div>";
    
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => 1]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $avatar = obterCustomizacaoAvatar($pdo, 1);
        echo "<p><strong>Usuário:</strong> " . htmlspecialchars($user['nome']) . "</p>";
        echo "<p><strong>Avatar Config:</strong></p>";
        echo "<pre>" . json_encode($avatar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        echo "<p><strong>SVG Renderizado:</strong></p>";
        echo "<div style=\"border: 2px dashed var(--accent-green); padding: 20px; text-align: center; margin: 20px 0;\">";
        echo renderizarAvatarSVG($avatar, 48);
        echo "</div>";
    }
    
    echo "</div>";

} catch (Exception $e) {
    echo "<div class=\"test-section\" style=\"border-left: 3px solid #ff6b6b;\">
        <div style=\"color: #ff6b6b; font-weight: bold; margin-bottom: 10px;\">❌ ERRO</div>
        <pre style=\"color: #ff9999;\">" . htmlspecialchars($e->getMessage()) . "</pre>
    </div>";
}

echo "</div>
</body>
</html>";
?>
