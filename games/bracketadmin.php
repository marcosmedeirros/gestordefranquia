<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

$adminKey = 'nba2026admin';
if (isset($_GET['admin']) && $_GET['admin'] === $adminKey) {
    $_SESSION['bracket_admin_ok'] = true;
}
$isAdmin = !empty($_SESSION['bracket_admin_ok']);

if (!$isAdmin) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acesso negado</title></head><body style="font-family:Arial,sans-serif;background:#0a0b0f;color:#fff;padding:24px;">';
    echo '<h2>Acesso negado</h2><p>Use o link de admin para acessar esta pagina.</p>';
    echo '<p><a style="color:#7cc3ff;" href="bracketadmin.php?admin=' . urlencode($adminKey) . '">Abrir com chave de admin</a></p>';
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
    status_pagamento ENUM('pendente','confirmado','rejeitado') NOT NULL DEFAULT 'pendente',
    picks JSON NULL,
    pontos INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status_pagamento)
)");

// Garante compatibilidade para bases antigas que ainda nao possuem o status "rejeitado"
$pdo->exec("ALTER TABLE nba_bracket_apostadores MODIFY status_pagamento ENUM('pendente','confirmado','rejeitado') NOT NULL DEFAULT 'pendente'");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_status_id'], $_POST['admin_status'])) {
    $id = (int)$_POST['admin_status_id'];
    $novoStatus = in_array($_POST['admin_status'], ['confirmado', 'pendente', 'rejeitado'], true) ? $_POST['admin_status'] : 'pendente';

    $stmt = $pdo->prepare('UPDATE nba_bracket_apostadores SET status_pagamento = ? WHERE id = ?');
    $stmt->execute([$novoStatus, $id]);

    header('Location: bracketadmin.php?ok=' . $novoStatus);
    exit;
}

$statusFilter = $_GET['status'] ?? 'todos';
if (!in_array($statusFilter, ['todos', 'pendente', 'confirmado', 'rejeitado'], true)) {
    $statusFilter = 'todos';
}

$where = '';
$params = [];
if ($statusFilter !== 'todos') {
    $where = 'WHERE status_pagamento = ?';
    $params[] = $statusFilter;
}

$stmtList = $pdo->prepare("SELECT id, nome, telefone, status_pagamento, pontos, created_at
    FROM nba_bracket_apostadores
    $where
    ORDER BY
        CASE WHEN status_pagamento='pendente' THEN 0 WHEN status_pagamento='confirmado' THEN 1 ELSE 2 END ASC,
        CASE WHEN status_pagamento='pendente' THEN created_at END DESC,
        CASE WHEN status_pagamento='confirmado' THEN pontos END DESC,
        created_at DESC
");
$stmtList->execute($params);
$apostadores = $stmtList->fetchAll(PDO::FETCH_ASSOC);
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
.admin-card.rejected {
    border-color: #7d2a31;
    background: linear-gradient(180deg, rgba(180, 45, 56, .14), var(--panel));
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
.status-pill.rejected { background: rgba(180, 45, 56, .2); color: #ff9ea6; }
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

.filter-bar {
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
    margin-bottom: .9rem;
}
.filter-link {
    background: var(--panel2);
    border: 1px solid var(--border);
    color: var(--text);
    text-decoration: none;
    border-radius: 999px;
    padding: .35rem .75rem;
    font-size: .8rem;
    font-weight: 700;
}
.filter-link.active {
    border-color: #4a4f67;
    background: #272b38;
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
    <?php if (isset($_GET['ok']) && $_GET['ok'] === 'rejeitado'): ?>
        <div class="alert alert-danger py-2">Pagamento marcado como rejeitado.</div>
    <?php endif; ?>

    <div class="filter-bar">
        <a class="filter-link <?= $statusFilter === 'todos' ? 'active' : '' ?>" href="bracketadmin.php?status=todos">Todos</a>
        <a class="filter-link <?= $statusFilter === 'pendente' ? 'active' : '' ?>" href="bracketadmin.php?status=pendente">Pendentes</a>
        <a class="filter-link <?= $statusFilter === 'confirmado' ? 'active' : '' ?>" href="bracketadmin.php?status=confirmado">Confirmados</a>
        <a class="filter-link <?= $statusFilter === 'rejeitado' ? 'active' : '' ?>" href="bracketadmin.php?status=rejeitado">Rejeitados</a>
    </div>

    <?php if (empty($apostadores)): ?>
        <div class="admin-card">Nenhum palpite encontrado.</div>
    <?php else: ?>
        <div class="d-grid gap-2">
            <?php foreach ($apostadores as $a):
                $isConfirmado = $a['status_pagamento'] === 'confirmado';
                $isPendente = $a['status_pagamento'] === 'pendente';
                $isRejeitado = $a['status_pagamento'] === 'rejeitado';
            ?>
            <div class="admin-card <?= $isPendente ? 'pending' : '' ?> <?= $isRejeitado ? 'rejected' : '' ?>">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($a['nome']) ?></div>
                        <div class="meta"><?= htmlspecialchars($a['telefone']) ?> | #<?= (int)$a['id'] ?> | <?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="status-pill <?= $isConfirmado ? 'confirmed' : ($isRejeitado ? 'rejected' : 'pending') ?>"><?= $isConfirmado ? 'confirmado' : ($isRejeitado ? 'rejeitado' : 'pendente') ?></span>
                        <span class="fw-bold" style="min-width:72px;text-align:right;"><?= (int)$a['pontos'] ?> pts</span>
                        <form method="POST" action="bracketadmin.php" class="m-0">
                            <input type="hidden" name="admin_status_id" value="<?= (int)$a['id'] ?>">
                            <input type="hidden" name="admin_status" value="confirmado">
                            <button type="submit" class="admin-btn approve" title="Confirmar pagamento"><i class="bi bi-check-lg"></i></button>
                        </form>
                        <form method="POST" action="bracketadmin.php" class="m-0">
                            <input type="hidden" name="admin_status_id" value="<?= (int)$a['id'] ?>">
                            <input type="hidden" name="admin_status" value="rejeitado">
                            <button type="submit" class="admin-btn reject" title="Marcar rejeitado"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
