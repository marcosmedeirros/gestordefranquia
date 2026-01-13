<?php
/**
 * Script de diagnóstico para problemas no "Meu Elenco"
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';

// Verificar sessão
$user = getUserSession();
if (!$user) {
    echo "<h2>Não autenticado</h2>";
    echo "<pre>" . print_r($_SESSION, true) . "</pre>";
    exit;
}

$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ?');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

// Contar todos os jogadores no sistema
$totalPlayers = $pdo->query('SELECT COUNT(*) FROM players')->fetchColumn();

// Buscar jogadores do time específico
$playersInTeam = 0;
$playersList = [];
if ($team) {
    $stmtPlayers = $pdo->prepare('SELECT * FROM players WHERE team_id = ?');
    $stmtPlayers->execute([$team['id']]);
    $playersList = $stmtPlayers->fetchAll();
    $playersInTeam = count($playersList);
}

// Buscar distribuição de jogadores por time
$playersByTeam = $pdo->query('SELECT team_id, COUNT(*) as count FROM players GROUP BY team_id')->fetchAll();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Roster</title>
    <style>
        body { font-family: Arial; background: #1a1a2e; color: #fff; padding: 20px; }
        .card { background: #16213e; padding: 20px; margin: 10px 0; border-radius: 8px; }
        .success { color: #00ff00; }
        .warning { color: #ffaa00; }
        .error { color: #ff4444; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #333; }
        th { background: #0f3460; }
    </style>
</head>
<body>
    <h1>🔍 Diagnóstico do Meu Elenco</h1>
    
    <div class="card">
        <h2>👤 Usuário Logado</h2>
        <p><strong>ID:</strong> <?= $user['id'] ?></p>
        <p><strong>Nome:</strong> <?= htmlspecialchars($user['name']) ?></p>
        <p><strong>Liga:</strong> <?= $user['league'] ?></p>
    </div>
    
    <div class="card">
        <h2>🏀 Time do Usuário</h2>
        <?php if ($team): ?>
            <p class="success">✅ Time encontrado!</p>
            <p><strong>ID do Time:</strong> <?= $team['id'] ?></p>
            <p><strong>Nome:</strong> <?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></p>
            <p><strong>Liga:</strong> <?= $team['league'] ?></p>
        <?php else: ?>
            <p class="error">❌ Usuário não possui time!</p>
        <?php endif; ?>
    </div>
    
    <div class="card">
        <h2>👥 Jogadores</h2>
        <p><strong>Total de jogadores no sistema:</strong> <?= $totalPlayers ?></p>
        <p><strong>Jogadores no time <?= $team['id'] ?? 'N/A' ?>:</strong> 
            <span class="<?= $playersInTeam > 0 ? 'success' : 'error' ?>">
                <?= $playersInTeam ?>
            </span>
        </p>
        
        <?php if ($playersInTeam == 0 && $team): ?>
            <p class="warning">⚠️ O time existe mas não tem jogadores! Você pode adicionar jogadores na página Meu Elenco.</p>
        <?php endif; ?>
        
        <h3>Distribuição por Time:</h3>
        <table>
            <tr><th>Team ID</th><th>Quantidade</th></tr>
            <?php foreach ($playersByTeam as $row): ?>
                <tr style="<?= $row['team_id'] == ($team['id'] ?? 0) ? 'background: #0f3460;' : '' ?>">
                    <td><?= $row['team_id'] ?></td>
                    <td><?= $row['count'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($playersByTeam)): ?>
                <tr><td colspan="2" class="warning">Nenhum jogador no sistema</td></tr>
            <?php endif; ?>
        </table>
    </div>
    
    <?php if ($playersInTeam > 0): ?>
    <div class="card">
        <h2>📋 Lista de Jogadores do Time</h2>
        <table>
            <tr><th>ID</th><th>Nome</th><th>Posição</th><th>OVR</th><th>Função</th></tr>
            <?php foreach ($playersList as $p): ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td><?= htmlspecialchars($p['name']) ?></td>
                    <td><?= $p['position'] ?></td>
                    <td><?= $p['ovr'] ?></td>
                    <td><?= $p['role'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
    
    <div class="card">
        <h2>🔧 Ações</h2>
        <p><a href="/my-roster.php" style="color: #00aaff;">← Voltar para Meu Elenco</a></p>
        <?php if ($team && $playersInTeam == 0): ?>
            <p class="warning">O time está vazio. Use o formulário em "Meu Elenco" para adicionar jogadores.</p>
        <?php endif; ?>
    </div>
</body>
</html>
