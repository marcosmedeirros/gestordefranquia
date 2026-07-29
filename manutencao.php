<?php
/**
 * Página exibida a qualquer visitante (inclusive não-logado, inclusive admin
 * de liga) enquanto o modo manutenção está ativo. Só admin GERAL é liberado
 * do bloqueio em backend/auth.php (checkMaintenanceGate) e nunca chega aqui.
 */
require_once __DIR__ . '/backend/config.php';
require_once __DIR__ . '/backend/db.php';
$pdo = db();

$message = null;
try {
    $stmt = $pdo->query("SELECT message FROM maintenance_mode WHERE id = 1 LIMIT 1");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $message = $row['message'] ?? null;
} catch (Throwable $e) {
    // segue sem mensagem customizada
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="theme-color" content="#fc0025">
    <title>Manutenção — FBA Manager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --red: #fc0025; --red-soft: color-mix(in srgb, var(--red) 10%, transparent);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a;
            --border: rgba(255,255,255,.08);
            --text: #f0f0f3; --text-2: #868690; --text-3: #7d7d85;
            --font: 'Montserrat', sans-serif; --radius: 16px;
        }
        :root[data-theme="light"] {
            --bg: #f6f7fb; --panel: #ffffff; --panel-2: #f2f4f8;
            --border: #e3e6ee; --text: #111217; --text-2: #5b6270; --text-3: #657080;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: var(--font); background: var(--bg); color: var(--text);
            display: flex; align-items: center; justify-content: center; padding: 24px;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            max-width: 460px; width: 100%; background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 40px 32px; text-align: center;
            box-shadow: 0 30px 80px -30px rgba(0,0,0,.5);
        }
        .icon {
            width: 68px; height: 68px; border-radius: 50%; background: var(--red-soft);
            display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;
            color: var(--red); font-size: 30px;
        }
        h1 { font-size: 21px; font-weight: 800; margin-bottom: 10px; }
        p { font-size: 14px; color: var(--text-2); line-height: 1.6; margin-bottom: 6px; }
        .msg { margin-top: 16px; padding: 12px 16px; background: var(--panel-2); border: 1px solid var(--border); border-radius: 10px; font-size: 13px; color: var(--text); }
        .foot { margin-top: 22px; font-size: 11px; color: var(--text-3); }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon"><i class="bi bi-tools"></i></div>
        <h1>Estamos em manutenção</h1>
        <p>O FBA Manager está passando por uma atualização rápida.</p>
        <p>Volte a tentar em alguns minutos.</p>
        <?php if (!empty($message)): ?>
        <div class="msg"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <div class="foot">FBA Brasil</div>
    </div>
</body>
</html>
