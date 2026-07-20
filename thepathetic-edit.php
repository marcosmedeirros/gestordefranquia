<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$thePatheticExtraEditors = ['gustavodossantosgonzaga58@gmail.com'];
$isThePatheticEditor = ($user['user_type'] ?? 'jogador') === 'admin'
    || in_array(strtolower($user['email'] ?? ''), $thePatheticExtraEditors, true);
if (!$isThePatheticEditor) {
    header('Location: /dashboard.php');
    exit;
}
$pdo  = db();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_pages (
        page_key VARCHAR(60) NOT NULL PRIMARY KEY,
        content MEDIUMTEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

$flash = null; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = (string)($_POST['content'] ?? '');
    try {
        $pdo->prepare("INSERT INTO site_pages (page_key, content) VALUES ('thepathetic', ?)
                       ON DUPLICATE KEY UPDATE content = ?, updated_at = CURRENT_TIMESTAMP")
            ->execute([$content, $content]);
        $flash = 'Conteúdo salvo com sucesso.';
    } catch (PDOException $e) {
        $flash = 'Erro ao salvar: ' . $e->getMessage();
        $flashType = 'danger';
    }
}

$currentContent = '';
try {
    $stmt = $pdo->prepare("SELECT content FROM site_pages WHERE page_key = 'thepathetic' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentContent = $row ? (string)($row['content'] ?? '') : '';
} catch (Exception $e) {}

$team = null;
try {
    $stmtT = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
    $stmtT->execute([$user['id']]);
    $team = $stmtT->fetch() ?: null;
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>The Pathetic — Editor · FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
        :root {
            --red:#fc0025; --red-soft:color-mix(in srgb, var(--red) 10%, transparent); --red-glow:color-mix(in srgb, var(--red) 18%, transparent);
            --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
            --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10); --border-red:color-mix(in srgb, var(--red) 22%, transparent);
            --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
            --green:#22c55e;
            --sidebar-w:260px; --font:'Montserrat', sans-serif;
            --radius:14px; --radius-sm:10px; --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
        }
        :root[data-theme="light"] {
            --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
            --border:#e3e6ee; --border-md:#d7dbe6; --border-red:color-mix(in srgb, var(--red) 18%, transparent);
            --text:#111217; --text-2:#5b6270; --text-3:#657080;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        html,body{height:100%;}
        body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;}
        .app{display:flex;min-height:100vh;}

        .sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;transition:transform var(--t) var(--ease);overflow-y:auto;scrollbar-width:none;}
        .sidebar::-webkit-scrollbar{display:none;}
        .sb-brand{padding:22px 18px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0;}
        .sb-logo{width:34px;height:34px;border-radius:9px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff;flex-shrink:0;}
        .sb-brand-text{font-weight:700;font-size:15px;line-height:1.1;}
        .sb-brand-text span{display:block;font-size:11px;font-weight:400;color:var(--text-2);}
        .sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px;flex-shrink:0;}
        .sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0;}
        .sb-team-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.2;}
        .sb-team-league{font-size:11px;color:var(--red);font-weight:600;}
        .sb-nav{flex:1;padding:12px 10px 8px;}
        .sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 6px;}
        .sb-nav a { font-family:'Inter',sans-serif;display:flex;align-items:center;gap:10px;padding:10px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease);}
        .sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0;}
        .sb-nav a:hover{background:var(--panel-2);color:var(--text);}
        .sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600;}
        .sb-nav a.active i{color:var(--red);}
        .sb-theme-toggle{margin:0 14px 12px;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--panel-2);color:var(--text);display:flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease);}
        .sb-theme-toggle:hover{border-color:var(--border-red);color:var(--red);}
        .sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0;}
        .sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0;}
        .sb-username{font-size:12px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;flex-shrink:0;}
        .sb-logout:hover{background:var(--red-soft);border-color:var(--red);color:var(--red);}

        .topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:240;}
        .topbar-title{font-weight:700;font-size:15px;flex:1;}
        .topbar-title em{color:var(--red);font-style:normal;}
        .menu-btn{width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:17px;}
        .sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:250;}
        .sb-overlay.show{display:block;}

        .main{margin-left:var(--sidebar-w);min-height:100vh;width:calc(100% - var(--sidebar-w));display:flex;flex-direction:column;}
        .page-hero{padding:32px 32px 0;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;}
        .page-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px;}
        .page-title{font-size:26px;font-weight:800;line-height:1.1;}
        .page-sub{font-size:13px;color: var(--text);margin-top:4px;}
        .content{padding:24px 32px 40px;flex:1;}

        .bc{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
        .bc-head{padding:16px 18px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;}
        .bc-title{font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
        .bc-title i{color:var(--red);font-size:15px;}
        .bc-body{padding:18px;}

        .flash{border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;}
        .flash.success{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.25);color:var(--green);}
        .flash.danger{background:var(--red-soft);border:1px solid var(--border-red);color:var(--red);}

        .btn-save{background:var(--red);border:none;color:#fff;font-family:var(--font);font-size:13px;font-weight:700;padding:10px 28px;border-radius:var(--radius-sm);cursor:pointer;transition:filter var(--t) var(--ease);}
        .btn-save:hover{filter:brightness(1.1);}
        .btn-outline{background:transparent;border:1px solid var(--border-md);color:var(--text);font-family:var(--font);font-size:13px;font-weight:600;padding:10px 20px;border-radius:var(--radius-sm);cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
        .btn-outline:hover{border-color:var(--border-red);color:var(--red);}

        .html-area{width:100%;min-height:440px;resize:vertical;font-family:monospace;font-size:13px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:14px;color:var(--text);outline:none;transition:border-color var(--t);line-height:1.6;}
        .html-area:focus{border-color:var(--red);}

        @media(max-width:992px){
            :root{--sidebar-w:0px;}
            .sidebar{transform:translateX(-260px);}
            .sidebar.open{transform:translateX(0);}
            .main{margin-left:0;width:100%;padding-top:54px;}
            .topbar{display:flex;}
            .page-hero,.content{padding-left:16px;padding-right:16px;}
            .page-hero{padding-top:18px;}
        }
        a{color:inherit;}
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>
<div class="app">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>
    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
    </header>

    <main class="main">
        <div class="page-hero">
            <div>
                <div class="page-eyebrow">Admin · The Pathetic</div>
                <h1 class="page-title">Editor — The Pathetic</h1>
                <p class="page-sub">Cole o HTML que será exibido na página pública do The Pathetic.</p>
            </div>
            <div style="padding-top:4px">
                <a class="btn-outline" href="/thepathetic.php" target="_blank" rel="noopener">
                    <i class="bi bi-box-arrow-up-right"></i> Ver página
                </a>
            </div>
        </div>

        <div class="content">
            <?php if ($flash): ?>
            <div class="flash <?= $flashType==='danger'?'danger':'success' ?> mb-4">
                <i class="bi bi-<?= $flashType==='danger'?'exclamation-circle-fill':'check-circle-fill' ?>"></i>
                <?= htmlspecialchars($flash) ?>
            </div>
            <?php endif; ?>

            <form method="post">
                <div class="d-flex flex-column gap-3">
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-title"><i class="bi bi-code-slash"></i> HTML da Página</div>
                            <span style="font-size:11px;color:var(--text-3)">O HTML inserido aqui será exibido diretamente na página pública</span>
                        </div>
                        <div class="bc-body">
                            <textarea name="content" class="html-area"
                                      placeholder="Cole seu HTML aqui ou escreva o que quiser..."><?= htmlspecialchars($currentContent) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a class="btn-outline" href="/thepathetic.php" target="_blank" rel="noopener">
                            <i class="bi bi-eye"></i> Visualizar
                        </a>
                        <button type="submit" class="btn-save">Salvar</button>
                    </div>
                </div>
            </form>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar   = document.getElementById('sidebar');
    const overlay   = document.getElementById('sbOverlay');
    const menuBtn   = document.getElementById('menuBtn');
    const themeToggle = document.getElementById('themeToggle');

    menuBtn?.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('show'); });
    overlay?.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); });

    function applyTheme(t) {
        document.documentElement.dataset.theme = t;
        localStorage.setItem('fba-theme', t);
        const i = themeToggle.querySelector('i'), s = themeToggle.querySelector('span');
        if (i) i.className = t==='dark'?'bi bi-moon':'bi bi-sun';
        if (s) s.textContent = t==='dark'?'Modo escuro':'Modo claro';
    }
    applyTheme(localStorage.getItem('fba-theme')||'dark');
    themeToggle.addEventListener('click', () => applyTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
</script>
</body>
</html>
