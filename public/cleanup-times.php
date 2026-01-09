<?php
session_start();
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';

// Verificar se é admin
$user = getUserSession();
if (!$user || $user['user_type'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['error' => 'Acesso negado. Apenas administradores.']));
}

$pdo = db();
$action = isset($_GET['action']) ? $_GET['action'] : 'view';
$result = null;

if ($action === 'cleanup') {
    // Verificar duplicatas
    $stmt = $pdo->prepare('
        SELECT league, user_id, name, COUNT(*) as cnt, GROUP_CONCAT(id) as ids
        FROM teams
        GROUP BY league, user_id, name
        HAVING cnt > 1
        ORDER BY league, user_id, name
    ');
    $stmt->execute();
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $removed = 0;
    $moved_players = 0;
    
    foreach ($duplicates as $dup) {
        $ids = array_map('intval', explode(',', $dup['ids']));
        $keepId = $ids[0];
        
        foreach (array_slice($ids, 1) as $removeId) {
            // Mover jogadores
            $moveStmt = $pdo->prepare('UPDATE players SET team_id = ? WHERE team_id = ?');
            $moveStmt->execute([$keepId, $removeId]);
            $moved_players += $moveStmt->rowCount();
            
            // Deletar time
            $delStmt = $pdo->prepare('DELETE FROM teams WHERE id = ?');
            $delStmt->execute([$removeId]);
            $removed++;
        }
    }
    
    $result = [
        'success' => true,
        'removed' => $removed,
        'moved_players' => $moved_players,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// Verificar duplicatas atuais
$stmt = $pdo->prepare('
    SELECT league, user_id, name, COUNT(*) as cnt, GROUP_CONCAT(CONCAT(id, " - ", city) SEPARATOR ", ") as teams
    FROM teams
    GROUP BY league, user_id, name
    HAVING cnt > 1
    ORDER BY league, user_id, name
');
$stmt->execute();
$duplicates_current = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Limpeza de Times - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .card { box-shadow: 0 8px 16px rgba(0,0,0,0.2); border: 0; }
        .badge-success { background: #28a745; }
        .badge-danger { background: #dc3545; }
    </style>
</head>
<body>
<div class="container mt-5">
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0">🧹 Limpeza de Times Duplicados</h4>
        </div>
        <div class="card-body">
            
            <?php if ($result): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <h5>✓ Limpeza Concluída!</h5>
                    <p class="mb-1"><strong>Removidos:</strong> <?= (int)$result['removed'] ?> times duplicados</p>
                    <p class="mb-1"><strong>Jogadores movidos:</strong> <?= (int)$result['moved_players'] ?></p>
                    <p class="mb-0"><strong>Horário:</strong> <?= htmlspecialchars($result['timestamp']) ?></p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <h5>📊 Status Atual</h5>
                <?php if (empty($duplicates_current)): ?>
                    <p class="mb-0 text-success"><i class="bi bi-check-circle"></i> <strong>Nenhuma duplicata encontrada!</strong></p>
                <?php else: ?>
                    <p class="mb-2"><strong>Duplicatas detectadas:</strong></p>
                    <ul class="mb-0">
                        <?php foreach ($duplicates_current as $dup): ?>
                            <li>
                                <strong><?= htmlspecialchars($dup['league']) ?></strong> - 
                                User <?= (int)$dup['user_id'] ?> - 
                                <span class="badge bg-danger"><?= (int)$dup['cnt'] ?>x <?= htmlspecialchars($dup['name']) ?></span>
                                <br><small class="text-muted"><?= htmlspecialchars($dup['teams']) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php if (!empty($duplicates_current)): ?>
                <form method="GET" class="mt-4">
                    <div class="alert alert-warning">
                        <strong>⚠️ Aviso:</strong> Esta ação vai:
                        <ol class="mb-0">
                            <li>Mover todos os jogadores dos times duplicados para o mantido</li>
                            <li>Deletar os times duplicados</li>
                            <li>A operação não pode ser desfeita</li>
                        </ol>
                    </div>
                    <button type="submit" name="action" value="cleanup" class="btn btn-danger btn-lg w-100" onclick="return confirm('Tem certeza? Esta ação vai remover os times duplicados!')">
                        <i class="bi bi-trash"></i> Executar Limpeza
                    </button>
                </form>
            <?php else: ?>
                <div class="alert alert-success text-center">
                    <h5 class="mb-2">✓ Tudo limpo!</h5>
                    <p class="mb-0">Você pode agora acessar /teams.php normalmente</p>
                </div>
            <?php endif; ?>

            <hr class="my-4">

            <div class="text-center">
                <a href="/admin.php" class="btn btn-secondary">← Voltar ao Admin</a>
                <a href="<?= $_SERVER['REQUEST_URI'] ?>" class="btn btn-info">🔄 Recarregar</a>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
