<?php
// Mostra o mesmo conteúdo que o admin gerencia em /thepathetic-edit.php
// (tabela site_pages, page_key='thepathetic'), só que com o visual novo.
// Não mexe no thepathetic.php/thepathetic-edit.php originais.
require_once __DIR__ . '/../backend/db.php';
$pdo = db();

$pageContent = '';
try {
    $stmt = $pdo->prepare("SELECT content FROM site_pages WHERE page_key = 'thepathetic' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $pageContent = $row ? (string)($row['content'] ?? '') : '';
} catch (Exception $e) {}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>The Pathetic · FBA</title>
<link rel="icon" type="image/png" href="/img/fba-logo-default-cropped.png" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Anton&family=Oswald:wght@500;700&family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root {
  --bg: #0a0a0a;
  --bg-2: #131313;
  --bg-3: #1a1a1a;
  --ink: #f5f5f5;
  --ink-mute: #a0a0a0;
  --ink-dim: #6a6a6a;
  --line: #262626;
  --line-2: #333;

  --red: #E63946;
  --red-deep: #b1232f;

  --max: 960px;
  --pad: clamp(20px, 4vw, 64px);

  --font-display: "Bebas Neue", "Anton", "Oswald", Impact, sans-serif;
  --font-body: "Inter", -apple-system, "Helvetica Neue", Arial, sans-serif;
  --font-mono: "JetBrains Mono", ui-monospace, "SF Mono", Menlo, monospace;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: var(--bg); color: var(--ink); }
body {
  font-family: var(--font-body);
  font-size: 16px;
  line-height: 1.6;
  -webkit-font-smoothing: antialiased;
}
img { max-width: 100%; display: block; }
a { color: var(--red); text-decoration: none; }
.wrap { max-width: var(--max); margin: 0 auto; padding-inline: var(--pad); }

.tp-topline { height: 4px; background: linear-gradient(90deg, var(--red), #ff6b35, var(--red)); background-size: 200%; animation: tpBar 3s linear infinite; }
@keyframes tpBar { 0% { background-position: 0% } 100% { background-position: 200% } }

.tp-hero { padding: 56px 0 32px; border-bottom: 1px solid var(--line); }
.tp-eyebrow {
  display: inline-flex; align-items: center; gap: 10px;
  padding: 6px 12px;
  border: 1px solid var(--red);
  color: var(--red);
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  margin-bottom: 20px;
}
.tp-title {
  font-family: var(--font-display);
  font-weight: 400;
  text-transform: uppercase;
  line-height: 0.9;
  font-size: clamp(48px, 9vw, 96px);
  margin: 0;
}
.tp-sub { color: var(--ink-mute); font-size: 15px; margin-top: 14px; }

.tp-content { padding: 48px 0 80px; font-size: 17px; line-height: 1.7; }
.tp-content h1, .tp-content h2, .tp-content h3 { font-family: var(--font-display); text-transform: uppercase; line-height: 1; letter-spacing: 0.01em; }
.tp-content h2 { font-size: 36px; margin: 40px 0 16px; }
.tp-content h3 { font-size: 24px; margin: 28px 0 12px; }
.tp-content p { margin: 0 0 18px; color: var(--ink-mute); }
.tp-content a { color: var(--red); }
.tp-content img { border: 1px solid var(--line); margin: 20px 0; }

.tp-empty { text-align: center; padding: 100px 24px; color: var(--ink-dim); }
.tp-empty i { font-size: 48px; display: block; margin-bottom: 16px; }
.tp-empty p { font-size: 15px; }

/* Matérias */
.foot {
  background: black;
  border-top: 1px solid var(--line);
  padding: 40px 0;
}
.foot-bottom {
  display: flex; justify-content: space-between;
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--ink-dim);
  flex-wrap: wrap; gap: 12px;
}
@media (max-width: 600px) { .wrap { padding-inline: 16px; } }
</style>
</head>
<body>
<div class="tp-topline"></div>
<?php include __DIR__ . '/_partials/nav.php'; ?>

<header class="tp-hero">
  <div class="wrap">
    <span class="tp-eyebrow"><i class="bi bi-newspaper"></i> Jornal da liga</span>
    <h1 class="tp-title">The<br />Pathetic.</h1>
    <p class="tp-sub">Cobertura, bastidores e análises de cada rodada, temporada e transferência da FBA.</p>
  </div>
</header>

<div class="wrap tp-content">
<?php if (trim($pageContent) !== ''): ?>
    <?= $pageContent ?>
<?php else: ?>
    <div class="tp-empty">
        <i class="bi bi-newspaper"></i>
        <p>Nenhum conteúdo publicado ainda.</p>
    </div>
<?php endif; ?>
</div>

<footer class="foot">
  <div class="wrap foot-bottom">
    <span>© 2026 FBA · Federação Brasileira de Arena</span>
    <span>Não afiliada à NBA ou Take-Two Interactive</span>
  </div>
</footer>
</body>
</html>
