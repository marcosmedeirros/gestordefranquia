<?php
/**
 * admin-games-controle.php — liga/desliga a pontuação em dobro por jogo,
 * dentro do fbabrasil.com.br (com a sidebar do site) e protegido pelo admin
 * do Games. Substitui o antigo games/admin/controlegames.php, que trazia o
 * menu do subdomínio.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();
$userId = (int) $user['id'];

ensureGamesSchema($pdo);

if (!hasGamesAdminAccess($pdo, $userId)) {
    header('Location: /dashboard.php');
    exit;
}

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$userId]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC) ?: null;
if ($team) { $team['photo_url'] = getTeamPhoto($team['photo_url'] ?? null); }

// Só os jogos que ficaram no catálogo depois da fusão.
$jogos = [
    'termo'     => ['label' => 'Termo',       'desc' => 'Diário · acerto vale moedas',  'icon' => 'bi-fonts'],
    'memoria'   => ['label' => 'Memória',     'desc' => 'Diário · acerto vale moedas',  'icon' => 'bi-grid-3x3-gap-fill'],
    'bomba'     => ['label' => 'Bomba',       'desc' => 'Diário · diamantes achados',   'icon' => 'bi-gem'],
    'quemsoueu' => ['label' => 'Quem Sou Eu?','desc' => 'Diário · acerto vale moedas',  'icon' => 'bi-question-circle'],
    'flappy'    => ['label' => 'Flappy Bird', 'desc' => 'Livre · pontuação da partida', 'icon' => 'bi-airplane'],
    'pinguim'   => ['label' => 'Pinguim Run', 'desc' => 'Livre · pontuação da partida', 'icon' => 'bi-snow'],
];

$mensagem = '';
$msgType  = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fba_game_controls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_key VARCHAR(40) NOT NULL UNIQUE,
            is_double TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $pdo->beginTransaction();
        $stmtUp = $pdo->prepare("
            INSERT INTO fba_game_controls (game_key, is_double) VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE is_double = VALUES(is_double)
        ");
        foreach (array_keys($jogos) as $key) {
            $ligado = isset($_POST['double'][$key]) && (int)$_POST['double'][$key] === 1 ? 1 : 0;
            $stmtUp->execute([':k' => $key, ':v' => $ligado]);
        }
        $pdo->commit();
        $mensagem = 'Configurações salvas.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[admin-games-controle] ' . $e->getMessage());
        $mensagem = 'Erro ao salvar as configurações.';
        $msgType  = 'danger';
    }
}

$config = array_fill_keys(array_keys($jogos), 0);
try {
    $stmtCfg = $pdo->query("SELECT game_key, is_double FROM fba_game_controls");
    foreach ($stmtCfg->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($config[$row['game_key']])) $config[$row['game_key']] = (int)$row['is_double'];
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#fc0025">
<title>Controle de Jogos — FBA Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<style>
    :root {
        --red:#fc0025; --red-soft:rgba(252,0,37,.10); --border-red:rgba(252,0,37,.22);
        --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
        --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
        --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
        --amber:#f59e0b; --green:#22c55e;
        --sidebar-w:260px; --font:'Montserrat',sans-serif;
        --radius:14px; --radius-sm:10px; --radius-xs:6px;
        --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
    }
    :root[data-theme="light"] {
        --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
        --border:#e3e6ee; --border-md:#d7dbe6; --text:#111217;
        --text-2:#5b6270; --text-3:#657080;
    }
    *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
    html,body { height:100%; }
    body { font-family:var(--font); background:var(--bg); color:var(--text); -webkit-font-smoothing:antialiased; }
    .app { display:flex; min-height:100vh; }
    .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--panel); border-right:1px solid var(--border); display:flex; flex-direction:column; z-index:300; overflow-y:auto; scrollbar-width:none; transition:transform var(--t) var(--ease); }
    .sidebar::-webkit-scrollbar { display:none; }
    .sb-brand { padding:22px 18px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:12px; flex-shrink:0; }
    .sb-logo { width:34px; height:34px; border-radius:9px; background:var(--red); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; color:#fff; flex-shrink:0; }
    .sb-brand-text { font-weight:700; font-size:15px; line-height:1.1; }
    .sb-brand-text span { display:block; font-size:11px; font-weight:400; color:var(--text-2); }
    .sb-team { margin:14px 14px 0; background:var(--panel-2); border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px; display:flex; align-items:center; gap:10px; flex-shrink:0; }
    .sb-team img { width:40px; height:40px; border-radius:9px; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
    .sb-team-name { font-size:13px; font-weight:600; color:var(--text); line-height:1.2; }
    .sb-team-league { font-size:11px; color:var(--red); font-weight:600; }
    .sb-nav { flex:1; padding:12px 10px 8px; }
    .sb-section { font-size:10px; font-weight:600; letter-spacing:1.2px; text-transform:uppercase; color:var(--text-3); padding:12px 10px 6px; }
    .sb-nav a { display:flex; align-items:center; gap:10px; padding:10px; border-radius:var(--radius-sm); color:var(--text-2); font-size:13px; font-weight:500; text-decoration:none; margin-bottom:2px; transition:all var(--t) var(--ease); }
    .sb-nav a i { font-size:15px; width:18px; text-align:center; flex-shrink:0; }
    .sb-nav a:hover { background:var(--panel-2); color:var(--text); }
    .sb-nav a.active { background:var(--red-soft); color:var(--red); font-weight:600; }
    .sb-nav a.active i { color:var(--red); }
    .sb-theme-toggle { margin:0 14px 12px; padding:8px 10px; border-radius:10px; border:1px solid var(--border); background:var(--panel-2); color:var(--text); display:flex; align-items:center; justify-content:center; gap:8px; font-size:12px; font-weight:600; cursor:pointer; }
    .sb-footer { padding:12px 14px; border-top:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-shrink:0; }
    .sb-avatar { width:30px; height:30px; border-radius:50%; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
    .sb-username { font-size:12px; font-weight:500; color:var(--text); flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .sb-logout { width:26px; height:26px; border-radius:7px; background:transparent; border:1px solid var(--border); color:var(--text-2); display:flex; align-items:center; justify-content:center; font-size:12px; cursor:pointer; text-decoration:none; flex-shrink:0; }
    .main { flex:1; margin-left:var(--sidebar-w); display:flex; flex-direction:column; min-width:0; }
    .topbar { display:none; position:fixed; top:0; left:0; right:0; height:54px; background:var(--panel); border-bottom:1px solid var(--border); align-items:center; padding:0 16px; gap:12px; z-index:260; }
    .topbar-title { font-weight:700; font-size:15px; flex:1; }
    .topbar-title em { color:var(--red); font-style:normal; }
    .menu-btn { background:none; border:none; color:var(--text); font-size:22px; cursor:pointer; }
    .sb-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:270; }
    .sb-overlay.open { display:block; }

    .page-hero { padding:28px 32px 0; }
    .page-eyebrow { font-size:11px; font-weight:600; letter-spacing:1.4px; text-transform:uppercase; color:var(--red); margin-bottom:4px; }
    .page-title { font-size:26px; font-weight:800; line-height:1.1; display:flex; align-items:center; gap:10px; }
    .page-title i { color:var(--red); }
    .page-sub { font-size:13px; color:var(--text-2); margin-top:4px; }
    .content { padding:24px 32px 56px; max-width:760px; }

    .btn-voltar { display:inline-flex; align-items:center; gap:7px; text-decoration:none; background:transparent;
        border:1px solid var(--border-md); color:var(--text-2); font-weight:600; font-size:12.5px;
        border-radius:8px; padding:8px 14px; margin-top:12px; }
    .btn-voltar:hover { border-color:var(--border-red); color:var(--red); }

    .alerta { border-radius:var(--radius-sm); padding:11px 15px; font-size:13px; margin-bottom:18px; }
    .alerta.success { background:rgba(34,197,94,.10); border:1px solid rgba(34,197,94,.30); color:var(--green); }
    .alerta.danger  { background:rgba(239,68,68,.10); border:1px solid rgba(239,68,68,.30); color:#ef4444; }

    .linha { display:flex; align-items:center; gap:14px; background:var(--panel); border:1px solid var(--border);
        border-radius:var(--radius); padding:15px 18px; margin-bottom:10px; }
    .linha-ico { width:40px; height:40px; border-radius:11px; background:var(--panel-3); color:var(--red);
        display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
    .linha-txt { flex:1; min-width:0; }
    .linha-txt b { display:block; font-size:14px; font-weight:700; }
    .linha-txt span { font-size:11.5px; color:var(--text-3); }

    .switch { position:relative; width:48px; height:26px; flex-shrink:0; }
    .switch input { opacity:0; width:0; height:0; }
    .switch .track { position:absolute; inset:0; background:var(--panel-3); border:1px solid var(--border-md);
        border-radius:26px; cursor:pointer; transition:all var(--t) var(--ease); }
    .switch .track::before { content:''; position:absolute; width:18px; height:18px; left:3px; top:3px;
        background:var(--text-3); border-radius:50%; transition:all var(--t) var(--ease); }
    .switch input:checked + .track { background:rgba(34,197,94,.20); border-color:rgba(34,197,94,.5); }
    .switch input:checked + .track::before { transform:translateX(22px); background:var(--green); }

    .btn-salvar { margin-top:18px; background:var(--red); border:none; color:#fff; font-family:var(--font);
        font-size:14px; font-weight:700; border-radius:var(--radius-sm); padding:12px 24px; cursor:pointer; }

    @media (max-width:992px) {
        :root { --sidebar-w: 0px; }
        .main { margin-left:0; padding-top:54px; }
        .topbar { display:flex; }
        .sidebar { transform:translateX(-260px); }
        .sidebar.open { transform:translateX(0); }
        .page-hero { padding:20px 18px 0; }
        .content { padding:18px 18px 44px; }
    }
<?php include __DIR__ . '/includes/accent-color.php'; ?>
    @media (prefers-reduced-motion: reduce) { *,*::before,*::after { transition-duration:.01ms !important; } }
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
        <div class="page-eyebrow">Admin · Games</div>
        <h1 class="page-title"><i class="bi bi-toggles"></i> Controle de Jogos</h1>
        <p class="page-sub">Ligue a pontuação em dobro nos jogos que quiser destacar.</p>
        <a href="/admin.php" class="btn-voltar"><i class="bi bi-arrow-left"></i> Voltar ao Admin</a>
    </div>

    <div class="content">
        <?php if ($mensagem): ?>
        <div class="alerta <?= htmlspecialchars($msgType) ?>">
            <i class="bi bi-<?= $msgType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
            <?= htmlspecialchars($mensagem) ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <?php foreach ($jogos as $key => $j): ?>
            <div class="linha">
                <div class="linha-ico"><i class="bi <?= htmlspecialchars($j['icon']) ?>"></i></div>
                <div class="linha-txt">
                    <b><?= htmlspecialchars($j['label']) ?></b>
                    <span><?= htmlspecialchars($j['desc']) ?></span>
                </div>
                <label class="switch">
                    <input type="checkbox" name="double[<?= htmlspecialchars($key) ?>]" value="1"
                           <?= $config[$key] === 1 ? 'checked' : '' ?>>
                    <span class="track"></span>
                </label>
            </div>
            <?php endforeach; ?>

            <button type="submit" class="btn-salvar"><i class="bi bi-check-lg"></i> Salvar</button>
        </form>
    </div>
</main>
</div>
<script>
    const _sb = document.getElementById('sidebar');
    const _ov = document.getElementById('sbOverlay');
    document.getElementById('menuBtn')?.addEventListener('click', () => { _sb?.classList.add('open'); _ov?.classList.add('open'); });
    _ov?.addEventListener('click', () => { _sb?.classList.remove('open'); _ov?.classList.remove('open'); });
</script>
</body>
</html>
