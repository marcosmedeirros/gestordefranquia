<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
if (($user['user_type'] ?? 'jogador') !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}

$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_pages (
        page_key VARCHAR(60) NOT NULL PRIMARY KEY,
        content MEDIUMTEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_games (
        slug VARCHAR(80) NOT NULL PRIMARY KEY,
        active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

$flash = null; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_pathetic') {
        $content = (string)($_POST['content'] ?? '');
        try {
            $pdo->prepare("INSERT INTO site_pages (page_key, content) VALUES ('thepathetic', ?)
                           ON DUPLICATE KEY UPDATE content = ?, updated_at = CURRENT_TIMESTAMP")
                ->execute([$content, $content]);
            $flash = 'Conteúdo do The Pathetic salvo com sucesso.';
        } catch (PDOException $e) {
            $flash = 'Erro ao salvar: ' . $e->getMessage();
            $flashType = 'danger';
        }
    } elseif ($action === 'save_games') {
        $activeSlugs = $_POST['active_games'] ?? [];
        try {
            foreach (glob(__DIR__ . '/site/gamesfba/*.php') as $file) {
                $slug = basename($file, '.php');
                $active = in_array($slug, $activeSlugs, true) ? 1 : 0;
                $pdo->prepare("INSERT INTO site_games (slug, active) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE active = ?, updated_at = CURRENT_TIMESTAMP")
                    ->execute([$slug, $active, $active]);
            }
            $flash = 'Status dos jogos atualizado.';
        } catch (PDOException $e) {
            $flash = 'Erro ao salvar: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

// Conteúdo atual do Pathetic
$currentContent = '';
try {
    $stmt = $pdo->prepare("SELECT content FROM site_pages WHERE page_key = 'thepathetic' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentContent = $row ? (string)($row['content'] ?? '') : '';
} catch (Exception $e) {}

// Jogos disponíveis + status ativo/inativo
$gameStatus = [];
try {
    $stmt = $pdo->query("SELECT slug, active FROM site_games");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $gameStatus[$row['slug']] = (bool)$row['active'];
    }
} catch (Exception $e) {}

$games = [];
foreach (glob(__DIR__ . '/site/gamesfba/*.php') as $file) {
    $slug = basename($file, '.php');
    $html = file_get_contents($file);
    $title = $slug;
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1])));
    }
    $games[] = [
        'slug'   => $slug,
        'title'  => $title,
        'active' => $gameStatus[$slug] ?? true,
    ];
}

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
    <title>Site Admin · FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
        :root {
            --red:#fc0025; --red-soft:rgba(252,0,37,.10); --red-glow:rgba(252,0,37,.18);
            --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
            --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10); --border-red:rgba(252,0,37,.22);
            --text:#f0f0f3; --text-2:#868690; --text-3:#48484f;
            --green:#22c55e;
            --sidebar-w:260px; --font:'Poppins',sans-serif;
            --radius:14px; --radius-sm:10px; --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
        }
        :root[data-theme="light"] {
            --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
            --border:#e3e6ee; --border-md:#d7dbe6; --border-red:rgba(252,0,37,.18);
            --text:#111217; --text-2:#5b6270; --text-3:#8b93a5;
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
        .sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 5px;}
        .sb-nav a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease);}
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
        .page-sub{font-size:13px;color: var(--text);margin-top:3px;}
        .content{padding:24px 32px 40px;flex:1;display:flex;flex-direction:column;gap:20px;}

        .bc{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
        .bc-head{padding:15px 18px 13px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;}
        .bc-title{font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
        .bc-title i{color:var(--red);font-size:15px;}
        .bc-body{padding:18px;}

        .flash{border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;}
        .flash.success{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.25);color:var(--green);}
        .flash.danger{background:var(--red-soft);border:1px solid var(--border-red);color:var(--red);}

        .btn-save{background:var(--red);border:none;color:#fff;font-family:var(--font);font-size:13px;font-weight:700;padding:10px 28px;border-radius:var(--radius-sm);cursor:pointer;transition:filter var(--t) var(--ease);}
        .btn-save:hover{filter:brightness(1.1);}
        .btn-outline{background:transparent;border:1px solid var(--border-md);color:var(--text);font-family:var(--font);font-size:13px;font-weight:600;padding:9px 20px;border-radius:var(--radius-sm);cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
        .btn-outline:hover{border-color:var(--border-red);color:var(--red);}

        .html-area{width:100%;min-height:320px;resize:vertical;font-family:monospace;font-size:13px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:14px;color:var(--text);outline:none;transition:border-color var(--t);line-height:1.6;}
        .html-area:focus{border-color:var(--red);}

        .game-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 0;border-bottom:1px solid var(--border);}
        .game-row:last-child{border-bottom:none;}
        .game-row-name{font-size:13px;font-weight:600;color:var(--text);}
        .game-row-slug{font-size:11px;color:var(--text-3);font-family:monospace;}
        .switch{position:relative;display:inline-block;width:40px;height:22px;flex-shrink:0;}
        .switch input{opacity:0;width:0;height:0;}
        .switch-slider{position:absolute;cursor:pointer;inset:0;background:var(--panel-3);border:1px solid var(--border-md);border-radius:999px;transition:all var(--t);}
        .switch-slider:before{position:absolute;content:"";height:16px;width:16px;left:2px;top:2px;background:var(--text-2);border-radius:50%;transition:all var(--t);}
        .switch input:checked + .switch-slider{background:var(--red-soft);border-color:var(--red);}
        .switch input:checked + .switch-slider:before{transform:translateX(18px);background:var(--red);}

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
    </style>
</head>
<body>
<div class="app">

    <aside class="sidebar" id="sidebar">
        <div class="sb-brand">
            <div class="sb-logo">FBA</div>
            <div class="sb-brand-text">FBA Manager<span>Liga <?= htmlspecialchars($user['league']) ?></span></div>
        </div>
        <?php if ($team): ?>
        <div class="sb-team">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars(trim(($team['city']??'').' '.($team['name']??''))) ?></div>
                <div class="sb-team-league"><?= htmlspecialchars($user['league']) ?></div>
            </div>
        </div>
        <?php endif; ?>
        <nav class="sb-nav">
            <div class="sb-section">Principal</div>
            <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
            <div class="sb-section">Admin</div>
            <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <a href="/siteadmin.php" class="active"><i class="bi bi-globe2"></i> Site Admin</a>
            <div class="sb-section">Site público</div>
            <a href="/site/site.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Ver site</a>
            <a href="/site/gamesfba.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> Ver Games</a>
            <a href="/site/pathetic.php" target="_blank" rel="noopener"><i class="bi bi-newspaper"></i> Ver The Pathetic</a>
        </nav>
        <button class="sb-theme-toggle" type="button" id="themeToggle">
            <i class="bi bi-moon"></i><span>Modo escuro</span>
        </button>
        <div class="sb-footer">
            <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url']??null)) ?>"
                 class="sb-avatar" alt=""
                 onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name']) ?>&background=1c1c21&color=fc0025'">
            <span class="sb-username"><?= htmlspecialchars($user['name']) ?></span>
            <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </aside>

    <div class="sb-overlay" id="sbOverlay"></div>
    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
    </header>

    <main class="main">
        <div class="page-hero">
            <div>
                <div class="page-eyebrow">Admin · Site FBA</div>
                <h1 class="page-title">Site Admin</h1>
                <p class="page-sub">Controle os jogos ativos e o conteúdo do The Pathetic no site público.</p>
            </div>
        </div>

        <div class="content">
            <?php if ($flash): ?>
            <div class="flash <?= $flashType==='danger'?'danger':'success' ?>">
                <i class="bi bi-<?= $flashType==='danger'?'exclamation-circle-fill':'check-circle-fill' ?>"></i>
                <?= htmlspecialchars($flash) ?>
            </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="save_games">
                <div class="bc">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-controller"></i> Jogos — FBA Games</div>
                        <a class="btn-outline" href="/site/gamesfba.php" target="_blank" rel="noopener">
                            <i class="bi bi-box-arrow-up-right"></i> Ver página
                        </a>
                    </div>
                    <div class="bc-body">
                        <?php if (empty($games)): ?>
                        <p style="font-size:13px;color:var(--text-3)">Nenhum jogo encontrado em site/gamesfba/.</p>
                        <?php else: ?>
                        <?php foreach ($games as $g): ?>
                        <div class="game-row">
                            <div>
                                <div class="game-row-name"><?= htmlspecialchars($g['title']) ?></div>
                                <div class="game-row-slug"><?= htmlspecialchars($g['slug']) ?>.php</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="active_games[]" value="<?= htmlspecialchars($g['slug']) ?>" <?= $g['active'] ? 'checked' : '' ?>>
                                <span class="switch-slider"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn-save">Salvar jogos</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <form method="post">
                <input type="hidden" name="action" value="save_pathetic">
                <div class="bc">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-newspaper"></i> The Pathetic — Conteúdo</div>
                        <a class="btn-outline" href="/site/pathetic.php" target="_blank" rel="noopener">
                            <i class="bi bi-box-arrow-up-right"></i> Ver página
                        </a>
                    </div>
                    <div class="bc-body">
                        <p style="font-size:11px;color:var(--text-3);margin-bottom:10px">
                            Mesmo conteúdo usado em /site/pathetic.php e /thepathetic.php — cole o HTML das matérias aqui.
                        </p>
                        <textarea name="content" class="html-area"
                                  placeholder="Cole seu HTML aqui ou escreva o que quiser..."><?= htmlspecialchars($currentContent) ?></textarea>
                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn-save">Salvar conteúdo</button>
                        </div>
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
