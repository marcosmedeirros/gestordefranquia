<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

$adminKey = 'nba2026admin';
$isAdmin = isset($_GET['admin']) && $_GET['admin'] === $adminKey;

if (!$isAdmin) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acesso negado</title></head><body style="font-family:Arial,sans-serif;background:#0a0b0f;color:#fff;padding:24px;">';
    echo '<h2>Acesso negado</h2><p>Use o link de admin para acessar esta pagina.</p>';
    echo '</body></html>';
    exit;
}

// --- DB ---
$host = 'localhost';
$dbname = 'u289267434_gamesfba';
$user = 'u289267434_gamesfba';
$pass = 'Gamesfba@123';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Erro DB: ' . $e->getMessage());
}

$pdo->exec("CREATE TABLE IF NOT EXISTS nba_bracket_apostadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    telefone VARCHAR(30) NOT NULL,
    status_pagamento ENUM('pendente','confirmado') NOT NULL DEFAULT 'pendente',
    picks JSON NULL,
    pontos INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status_pagamento)
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_status_id'], $_POST['admin_status'])) {
    $id = (int)$_POST['admin_status_id'];
    $novoStatus = $_POST['admin_status'] === 'confirmado' ? 'confirmado' : 'pendente';

    $stmt = $pdo->prepare('UPDATE nba_bracket_apostadores SET status_pagamento = ? WHERE id = ?');
    $stmt->execute([$novoStatus, $id]);

    header('Location: bracketadmin.php?admin=' . urlencode($adminKey) . '&ok=' . $novoStatus);
    exit;
}

$apostadores = $pdo->query("SELECT id, nome, telefone, status_pagamento, pontos, picks, created_at
    FROM nba_bracket_apostadores
    ORDER BY
        CASE WHEN status_pagamento='pendente' THEN 0 ELSE 1 END ASC,
        CASE WHEN status_pagamento='pendente' THEN created_at END DESC,
        CASE WHEN status_pagamento='confirmado' THEN pontos END DESC,
        created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

function formatPickLabel($key) {
    $label = str_replace('_', ' ', $key);
    return strtoupper($label);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bracket Admin - NBA 2026</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root {
    --bg: #0a0b0f;
    --panel: #13141a;
    --panel2: #1a1c25;
    --border: #252734;
    --text: #e9eaee;
    --muted: #8a90a8;
}
body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Segoe UI', system-ui, sans-serif;
}
.container-admin {
    max-width: 1100px;
    margin: 0 auto;
    padding: 2rem 1rem 3rem;
}
.admin-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1rem;
}
.admin-card.pending {
    border-color: #8a6b2a;
    background: linear-gradient(180deg, rgba(255, 152, 0, .12), var(--panel));
    box-shadow: 0 0 0 0 rgba(255, 179, 71, .28);
    animation: pendingPulse 1.9s ease-in-out infinite;
}
@keyframes pendingPulse {
    0% { box-shadow: 0 0 0 0 rgba(255, 179, 71, .28); }
    70% { box-shadow: 0 0 0 9px rgba(255, 179, 71, 0); }
    100% { box-shadow: 0 0 0 0 rgba(255, 179, 71, 0); }
}
.status-pill {
    font-size: .72rem;
    font-weight: 800;
    padding: .2rem .55rem;
    border-radius: 999px;
    text-transform: uppercase;
}
.status-pill.pending { background: rgba(255, 152, 0, .16); color: #ffca6a; }
.status-pill.confirmed { background: rgba(76, 175, 80, .16); color: #87df8d; }
.pick-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: .45rem;
    margin-top: .65rem;
}
.pick-item {
    background: var(--panel2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: .4rem .55rem;
    font-size: .72rem;
}
.pick-item strong { color: #fff; }
.admin-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.admin-btn.approve { background: #2f9f49; }
.admin-btn.reject { background: #7d2a31; }
.meta {
    font-size: .78rem;
    color: var(--muted);
}
</style>
</head>
<body>
<div class="container-admin">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h3 class="m-0"><i class="bi bi-shield-check"></i> Bracket Admin - Todos os Palpites</h3>
        <a class="btn btn-outline-light btn-sm" href="bracketnba.php">Abrir pagina publica</a>
    </div>

    <?php if (isset($_GET['ok']) && $_GET['ok'] === 'confirmado'): ?>
        <div class="alert alert-success py-2">Pagamento confirmado.</div>
    <?php endif; ?>
    <?php if (isset($_GET['ok']) && $_GET['ok'] === 'pendente'): ?>
        <div class="alert alert-warning py-2">Pagamento marcado como pendente.</div>
    <?php endif; ?>

    <?php if (empty($apostadores)): ?>
        <div class="admin-card">Nenhum palpite encontrado.</div>
    <?php else: ?>
        <div class="d-grid gap-2">
            <?php foreach ($apostadores as $a):
                $isConfirmado = $a['status_pagamento'] === 'confirmado';
                $picks = json_decode($a['picks'] ?? '{}', true) ?: [];
            ?>
            <div class="admin-card <?= $isConfirmado ? '' : 'pending' ?>">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($a['nome']) ?></div>
                        <div class="meta"><?= htmlspecialchars($a['telefone']) ?> | #<?= (int)$a['id'] ?> | <?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="status-pill <?= $isConfirmado ? 'confirmed' : 'pending' ?>"><?= $isConfirmado ? 'confirmado' : 'pendente' ?></span>
                        <span class="fw-bold" style="min-width:72px;text-align:right;"><?= (int)$a['pontos'] ?> pts</span>
                        <form method="POST" action="bracketadmin.php?admin=<?= urlencode($adminKey) ?>" class="m-0">
                            <input type="hidden" name="admin_status_id" value="<?= (int)$a['id'] ?>">
                            <input type="hidden" name="admin_status" value="confirmado">
                            <button type="submit" class="admin-btn approve" title="Confirmar pagamento"><i class="bi bi-check-lg"></i></button>
                        </form>
                        <form method="POST" action="bracketadmin.php?admin=<?= urlencode($adminKey) ?>" class="m-0">
                            <input type="hidden" name="admin_status_id" value="<?= (int)$a['id'] ?>">
                            <input type="hidden" name="admin_status" value="pendente">
                            <button type="submit" class="admin-btn reject" title="Marcar pendente"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </div>
                </div>
                <?php if (empty($picks)): ?>
                    <div class="meta mt-2">Sem palpites salvos.</div>
                <?php else: ?>
                    <div class="pick-grid">
                        <?php foreach ($picks as $k => $v): ?>
                            <div class="pick-item"><strong><?= htmlspecialchars(formatPickLabel($k)) ?>:</strong> <?= htmlspecialchars((string)$v) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
