<?php
require_once __DIR__ . '/backend/db.php';

$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_pages (
        page_key VARCHAR(60) NOT NULL PRIMARY KEY,
        content MEDIUMTEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

$pageContent = '';
try {
    $stmt = $pdo->prepare("SELECT content FROM site_pages WHERE page_key = 'thepathetic' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $pageContent = $row ? (string)($row['content'] ?? '') : '';
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Pathetic – FBA</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --red: #fc0025; }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #07070a; color: #f0f0f3; font-family: 'Segoe UI', system-ui, sans-serif; min-height: 100vh; }
        .tp-top-bar { height: 4px; background: linear-gradient(90deg, var(--red), #ff6b35, var(--red)); background-size: 200%; animation: tpBar 3s linear infinite; }
        @keyframes tpBar { 0% { background-position: 0% } 100% { background-position: 200% } }
        .tp-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 32px; border-bottom: 1px solid rgba(255,255,255,.06); }
        .tp-brand { display: flex; align-items: center; gap: 12px; }
        .tp-brand-logo { font-size: 11px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: var(--red); border: 1px solid color-mix(in srgb, var(--red) 35%, transparent); border-radius: 6px; padding: 3px 8px; }
        .tp-brand-title { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; color: #f0f0f3; }
        .tp-back { color: rgba(255,255,255,.4); font-size: 13px; text-decoration: none; display: flex; align-items: center; gap: 6px; transition: color .2s; }
        .tp-back:hover { color: var(--red); }
        .tp-empty { text-align: center; padding: 100px 24px; color: rgba(255,255,255,.25); }
        .tp-empty i { font-size: 48px; display: block; margin-bottom: 16px; }
        .tp-empty p { font-size: 15px; }
        .tp-content { max-width: 960px; margin: 0 auto; padding: 32px 24px 60px; }
        @media (max-width: 600px) { .tp-header { padding: 14px 16px; } .tp-content { padding: 20px 16px 40px; } }
    </style>
</head>
<body>
<div class="tp-top-bar"></div>
<header class="tp-header">
    <div class="tp-brand">
        <span class="tp-brand-logo">FBA</span>
        <span class="tp-brand-title">The Pathetic</span>
    </div>
    <a class="tp-back" href="/dashboard.php"><i class="bi bi-arrow-left"></i> Voltar</a>
</header>

<div class="tp-content">
<?php if (trim($pageContent) !== ''): ?>
    <?= $pageContent ?>
<?php else: ?>
    <div class="tp-empty">
        <i class="bi bi-newspaper"></i>
        <p>Nenhum conteúdo publicado ainda.</p>
    </div>
<?php endif; ?>
</div>
</body>
</html>
