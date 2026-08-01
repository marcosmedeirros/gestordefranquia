<?php
/**
 * games.php — Games e Apostas dentro do fbabrasil.com.br.
 *
 * Depois da fusão o antigo subdomínio deixou de existir: o catálogo de
 * minigames e as apostas vivem aqui, no mesmo banco e na mesma sessão do
 * site. Os jogos em si continuam sendo servidos de /games/, que agora usa
 * o backend/auth.php daqui.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();
$userId = (int) $user['id'];

$nowBrt    = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$nowBrtStr = $nowBrt->format('Y-m-d H:i:s');

// Perfil de jogo — a linha nasce no primeiro acesso, igual ao core/conexao.php
$perfil = ['pontos' => 0, 'fba_points' => 0, 'acertos_eventos' => 0];
try {
    $st = $pdo->prepare("SELECT pontos, fba_points, acertos_eventos FROM games_usuarios WHERE id = ?");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->prepare("
            INSERT IGNORE INTO games_usuarios (id, nome, email, league, is_admin)
            SELECT id, name, email, COALESCE(league,'ROOKIE'), ? FROM users WHERE id = ?
        ")->execute([(($user['user_type'] ?? '') === 'admin') ? 1 : 0, $userId]);
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }
    if ($row) $perfil = $row;
} catch (Throwable $e) {
    error_log('[games.php] perfil: ' . $e->getMessage());
}

// ── Registrar palpite ───────────────────────────────────────────────────────
$apostaMsg = null;
$apostaErro = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['opcao_id'])) {
    try {
        $opcaoId = (int) $_POST['opcao_id'];
        if ($opcaoId <= 0) throw new Exception('Escolha inválida.');

        $pdo->beginTransaction();
        $stC = $pdo->prepare("
            SELECT e.id AS evento_id, e.status, e.data_limite
            FROM opcoes o JOIN eventos e ON o.evento_id = e.id
            WHERE o.id = ?
        ");
        $stC->execute([$opcaoId]);
        $ev = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$ev) throw new Exception('Opção inválida.');
        if ($ev['status'] !== 'aberta') throw new Exception('Esse evento já encerrou.');
        if (new DateTime($ev['data_limite'], new DateTimeZone('America/Sao_Paulo')) < $nowBrt) {
            throw new Exception('O prazo desse evento já passou.');
        }

        $stD = $pdo->prepare("
            SELECT p.id FROM palpites p JOIN opcoes o ON p.opcao_id = o.id
            WHERE p.id_usuario = ? AND o.evento_id = ? LIMIT 1
        ");
        $stD->execute([$userId, $ev['evento_id']]);
        $existente = $stD->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            $pdo->prepare("UPDATE palpites SET opcao_id = ?, valor = 1, odd_registrada = 1, data_palpite = NOW() WHERE id = ?")
                ->execute([$opcaoId, $existente['id']]);
            $apostaMsg = 'Palpite atualizado.';
        } else {
            $pdo->prepare("INSERT INTO palpites (id_usuario, opcao_id, valor, odd_registrada, data_palpite) VALUES (?, ?, 1, 1, NOW())")
                ->execute([$userId, $opcaoId]);
            $apostaMsg = 'Palpite registrado.';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $apostaErro = $e->getMessage();
    }
}

// ── Eventos abertos ─────────────────────────────────────────────────────────
$eventos = [];
try {
    $stE = $pdo->prepare("SELECT id, nome, data_limite FROM eventos WHERE status = 'aberta' AND data_limite > ? ORDER BY data_limite ASC LIMIT 50");
    $stE->execute([$nowBrtStr]);
    $eventos = $stE->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($eventos as &$ev) {
        $stO = $pdo->prepare("SELECT id, descricao FROM opcoes WHERE evento_id = ? ORDER BY id ASC");
        $stO->execute([$ev['id']]);
        $ev['opcoes'] = $stO->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stM = $pdo->prepare("
            SELECT p.opcao_id FROM palpites p JOIN opcoes o ON p.opcao_id = o.id
            WHERE p.id_usuario = ? AND o.evento_id = ? LIMIT 1
        ");
        $stM->execute([$userId, $ev['id']]);
        $ev['meu_palpite'] = (int) ($stM->fetchColumn() ?: 0);
    }
    unset($ev);
} catch (Throwable $e) {
    $eventos = [];
}

// ── Meus palpites ───────────────────────────────────────────────────────────
$historico = [];
try {
    $stH = $pdo->prepare("
        SELECT p.data_palpite, o.descricao AS escolha, e.nome AS evento,
               e.status AS evento_status, e.vencedor_opcao_id, p.opcao_id
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = ?
        ORDER BY p.data_palpite DESC LIMIT 40
    ");
    $stH->execute([$userId]);
    $historico = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $historico = [];
}

// ── Catálogo de minigames ───────────────────────────────────────────────────
$jogosDiarios = [
    ['key' => 'termo',     'nome' => 'Termo',       'sub' => 'Adivinhe a palavra',  'icone' => 'bi-fonts',            'cor' => '#22c55e'],
    ['key' => 'memoria',   'nome' => 'Memória',     'sub' => 'Ache os pares',       'icone' => 'bi-grid-3x3-gap-fill','cor' => '#a855f7'],
    ['key' => 'boxnba',    'nome' => 'Box NBA',     'sub' => 'Quem é o jogador?',   'icone' => 'bi-box',              'cor' => '#f59e0b'],
    ['key' => 'quemsoueu', 'nome' => 'Quem Sou Eu?','sub' => 'Descubra pelas dicas','icone' => 'bi-question-circle',  'cor' => '#3b82f6'],
    ['key' => 'bomba',     'nome' => 'Bomba',       'sub' => 'Ache os diamantes',   'icone' => 'bi-gem',              'cor' => '#ef4444'],
    ['key' => 'grade',     'nome' => 'Grade NBA',   'sub' => 'Preencha a grade',    'icone' => 'bi-grid-3x3',         'cor' => '#06b6d4'],
    ['key' => 'hoopgrid',  'nome' => 'Hoop Grid',   'sub' => 'Cruze os times',      'icone' => 'bi-diagram-3',        'cor' => '#ec4899'],
    ['key' => 'conexoes',  'nome' => 'Conexões',    'sub' => 'Agrupe por tema',     'icone' => 'bi-link-45deg',       'cor' => '#8b5cf6'],
];
$jogosLivres = [
    ['key' => 'flappy',    'nome' => 'Flappy Bird', 'sub' => 'Desvie dos canos',  'icone' => 'bi-airplane',      'cor' => '#f43f5e'],
    ['key' => 'pinguim',   'nome' => 'Pinguim Run', 'sub' => 'Corra e ganhe',     'icone' => 'bi-snow',          'cor' => '#38bdf8'],
    ['key' => 'xadrez',    'nome' => 'Xadrez',      'sub' => 'Desafie um GM',     'icone' => 'bi-regex',         'cor' => '#94a3b8'],
    ['key' => 'poker',     'nome' => 'Poker',       'sub' => 'Texas Hold\'em',    'icone' => 'bi-suit-spade-fill','cor' => '#eab308'],
    ['key' => 'blackjack', 'nome' => 'Blackjack',   'sub' => 'Chegue a 21',       'icone' => 'bi-suit-heart-fill','cor' => '#ef4444'],
    ['key' => 'roleta',    'nome' => 'Roleta',      'sub' => 'Cassino europeu',   'icone' => 'bi-record-circle', 'cor' => '#22c55e'],
    ['key' => 'penalti',   'nome' => 'Pênaltis',    'sub' => 'Bata e defenda',    'icone' => 'bi-dribbble',      'cor' => '#84cc16'],
    ['key' => 'pnipnaval', 'nome' => 'Batalha Naval','sub' => 'Afunde a frota',   'icone' => 'bi-water',         'cor' => '#0ea5e9'],
    ['key' => 'acerteacesta','nome' => 'Acerte a Cesta','sub' => 'Lance livre',   'icone' => 'bi-bullseye',      'cor' => '#f97316'],
];

$abaInicial = (isset($_GET['aba']) && $_GET['aba'] === 'apostas') || $apostaMsg || $apostaErro ? 'apostas' : 'games';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#fc0025">
<title>Games - FBA Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<style>
    :root {
        --red:#fc0025; --red-soft:rgba(252,0,37,.10); --border-red:rgba(252,0,37,.22);
        --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
        --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
        --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
        --amber:#f59e0b; --green:#22c55e; --blue:#3b82f6;
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
        .sb-nav a { font-family:'Inter',sans-serif; display:flex; align-items:center; gap:10px; padding:10px 10px; border-radius:var(--radius-sm); color:var(--text-2); font-size:13px; font-weight:500; text-decoration:none; margin-bottom:2px; transition:all var(--t) var(--ease); }
        .sb-nav a i { font-size:15px; width:18px; text-align:center; flex-shrink:0; }
        .sb-nav a:hover { background:var(--panel-2); color:var(--text); }
        .sb-nav a.active { background:var(--red-soft); color:var(--red); font-weight:600; }
        .sb-nav a.active i { color:var(--red); }
        .sb-footer { padding:12px 14px; border-top:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .sb-avatar { width:30px; height:30px; border-radius:50%; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
        .sb-username { font-size:12px; font-weight:500; color:var(--text); flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sb-logout { width:26px; height:26px; border-radius:7px; background:transparent; border:1px solid var(--border); color:var(--text-2); display:flex; align-items:center; justify-content:center; font-size:12px; cursor:pointer; transition:all var(--t) var(--ease); text-decoration:none; flex-shrink:0; }
        .sb-logout:hover { background:var(--red-soft); border-color:var(--red); color:var(--red); }
        .sb-theme-toggle { margin:0 14px 12px; padding:8px 10px; border-radius:10px; border:1px solid var(--border); background:var(--panel-2); color:var(--text); display:flex; align-items:center; justify-content:center; gap:8px; font-size:12px; font-weight:600; cursor:pointer; transition:all var(--t) var(--ease); }
        .sb-theme-toggle:hover { border-color:var(--border-red); color:var(--red); }
    .main { flex:1; margin-left:var(--sidebar-w); display:flex; flex-direction:column; min-width:0; }

    .page-hero { padding:28px 32px 0; }
    .page-eyebrow { font-size:11px; font-weight:600; letter-spacing:1.4px; text-transform:uppercase; color:var(--red); margin-bottom:4px; }
    .page-title { font-size:26px; font-weight:800; line-height:1.1; display:flex; align-items:center; gap:10px; }
    .page-title i { color:var(--red); }
    .page-sub { font-size:13px; color:var(--text-2); margin-top:4px; }
    .content { padding:24px 32px 56px; flex:1; }

    .topbar { display:none; position:fixed; top:0; left:0; right:0; height:54px; background:var(--panel); border-bottom:1px solid var(--border); align-items:center; padding:0 16px; gap:12px; z-index:260; }
    .topbar-title { font-weight:700; font-size:15px; flex:1; }
    .topbar-title em { color:var(--red); font-style:normal; }
    .menu-btn { background:none; border:none; color:var(--text); font-size:22px; cursor:pointer; }
    .sb-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:270; }
    .sb-overlay.open { display:block; }

    /* saldos */
    .saldos { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:22px; }
    .saldo { display:flex; align-items:center; gap:9px; background:var(--panel); border:1px solid var(--border-md);
             border-radius:var(--radius-sm); padding:10px 16px; }
    .saldo i { font-size:17px; }
    .saldo-val { font-size:17px; font-weight:800; line-height:1; }
    .saldo-lbl { font-size:10.5px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-3); margin-top:2px; }

    /* abas */
    .g-tabs { display:flex; border-bottom:1px solid var(--border); margin-bottom:26px; }
    .g-tab { display:flex; align-items:center; gap:8px; padding:12px 24px; font-size:13px; font-weight:600;
             color:var(--text-2); cursor:pointer; border:none; background:none; position:relative;
             font-family:var(--font); transition:color var(--t) var(--ease); }
    .g-tab::after { content:''; position:absolute; bottom:-1px; left:0; right:0; height:2px; background:transparent;
                    transition:background var(--t) var(--ease); }
    .g-tab.active { color:var(--text); }
    .g-tab.active::after { background:var(--red); }
    .g-pane { display:none; }
    .g-pane.active { display:block; }

    /* catálogo */
    .sec-label { font-size:11px; font-weight:800; letter-spacing:1.2px; text-transform:uppercase;
                 color:var(--text-3); margin:0 0 14px; display:flex; align-items:center; gap:8px; }
    .sec-label i { color:var(--red); font-size:13px; }
    .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(178px,1fr)); gap:14px; margin-bottom:34px; }
    .card-jogo { display:flex; flex-direction:column; align-items:flex-start; gap:3px; text-decoration:none;
                 background:var(--panel); border:1px solid var(--border); border-radius:var(--radius);
                 padding:18px 16px; transition:all var(--t) var(--ease); }
    .card-jogo:hover { border-color:var(--border-md); transform:translateY(-2px); }
    .card-jogo .ico { width:42px; height:42px; border-radius:11px; display:flex; align-items:center;
                      justify-content:center; font-size:20px; margin-bottom:9px; }
    .card-jogo .nome { font-size:14px; font-weight:700; color:var(--text); }
    .card-jogo .sub { font-size:11.5px; color:var(--text-3); }

    /* apostas */
    .card { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); margin-bottom:16px; }
    .card-head { padding:14px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center;
                 justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .card-head-left { display:flex; align-items:center; gap:9px; font-weight:700; font-size:14px; }
    .card-head-left i { color:var(--red); }
    .prazo { font-size:11.5px; color:var(--text-3); display:flex; align-items:center; gap:5px; }
    .card-body { padding:16px 18px; }
    .opcoes { display:flex; gap:10px; flex-wrap:wrap; }
    .op-btn { flex:1; min-width:150px; background:var(--panel-2); border:1px solid var(--border-md); color:var(--text);
              border-radius:var(--radius-sm); padding:12px 16px; font-family:var(--font); font-size:13px;
              font-weight:600; cursor:pointer; transition:all var(--t) var(--ease); text-align:left; }
    .op-btn:hover { border-color:var(--border-red); color:var(--red); }
    .op-btn.escolhida { border-color:var(--green); color:var(--green); background:rgba(34,197,94,.08); }
    .op-btn.escolhida::before { content:'\F26E'; font-family:'bootstrap-icons'; margin-right:7px; }

    .alerta { border-radius:var(--radius-sm); padding:11px 15px; font-size:13px; margin-bottom:16px; }
    .alerta.ok { background:rgba(34,197,94,.10); border:1px solid rgba(34,197,94,.30); color:var(--green); }
    .alerta.err { background:rgba(239,68,68,.10); border:1px solid rgba(239,68,68,.30); color:#ef4444; }

    .vazio { text-align:center; padding:44px 20px; color:var(--text-3); }
    .vazio i { font-size:30px; display:block; margin-bottom:10px; opacity:.5; }
    .vazio p { font-size:13px; }

    table.hist { width:100%; border-collapse:collapse; }
    table.hist th { text-align:left; font-size:10.5px; font-weight:800; letter-spacing:.6px; text-transform:uppercase;
                    color:var(--text-3); padding:0 12px 10px; }
    table.hist td { padding:11px 12px; border-top:1px solid var(--border); font-size:13px; }
    .pill { font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .pill.aberta { background:rgba(59,130,246,.12); color:var(--blue); }
    .pill.acertou { background:rgba(34,197,94,.12); color:var(--green); }
    .pill.errou { background:rgba(239,68,68,.12); color:#ef4444; }
    .tbl-wrap { overflow-x:auto; }

    @media (max-width:992px) {
        :root { --sidebar-w: 0px; }
        .main { margin-left:0; padding-top:54px; }
        .topbar { display:flex; }
            .sidebar { transform:translateX(-260px); }
            .sidebar.open { transform:translateX(0); }
        .page-hero { padding:20px 18px 0; }
        .content { padding:18px 18px 44px; }
        .g-tab { flex:1; justify-content:center; padding:12px 10px; }
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
        <div class="page-eyebrow">Diversão da liga</div>
        <h1 class="page-title"><i class="bi bi-controller"></i> Games</h1>
        <p class="page-sub">Minigames diários pra ganhar moedas e as apostas dos eventos da FBA.</p>
    </div>

    <div class="content">

        <div class="saldos">
            <div class="saldo">
                <i class="bi bi-coin" style="color:var(--amber)"></i>
                <div>
                    <div class="saldo-val"><?= number_format((int)$perfil['pontos'], 0, ',', '.') ?></div>
                    <div class="saldo-lbl">Moedas</div>
                </div>
            </div>
            <div class="saldo">
                <i class="bi bi-star-fill" style="color:var(--red)"></i>
                <div>
                    <div class="saldo-val"><?= number_format((int)$perfil['fba_points'], 0, ',', '.') ?></div>
                    <div class="saldo-lbl">FBA Points</div>
                </div>
            </div>
            <div class="saldo">
                <i class="bi bi-trophy-fill" style="color:var(--green)"></i>
                <div>
                    <div class="saldo-val"><?= number_format((int)$perfil['acertos_eventos'], 0, ',', '.') ?></div>
                    <div class="saldo-lbl">Acertos</div>
                </div>
            </div>
        </div>

        <div class="g-tabs">
            <button class="g-tab <?= $abaInicial === 'games' ? 'active' : '' ?>" data-aba="games" onclick="trocarAba('games')">
                <i class="bi bi-joystick"></i> Games
            </button>
            <button class="g-tab <?= $abaInicial === 'apostas' ? 'active' : '' ?>" data-aba="apostas" onclick="trocarAba('apostas')">
                <i class="bi bi-graph-up-arrow"></i> Apostas
            </button>
        </div>

        <!-- ── Aba Games ─────────────────────────────────────────────── -->
        <div class="g-pane <?= $abaInicial === 'games' ? 'active' : '' ?>" id="pane-games">
            <div class="sec-label"><i class="bi bi-calendar-check-fill"></i> Minigames diários</div>
            <div class="grid">
                <?php foreach ($jogosDiarios as $j): ?>
                <a class="card-jogo" href="/games/games/index.php?game=<?= urlencode($j['key']) ?>">
                    <div class="ico" style="background:<?= $j['cor'] ?>1f;color:<?= $j['cor'] ?>"><i class="bi <?= $j['icone'] ?>"></i></div>
                    <div class="nome"><?= htmlspecialchars($j['nome']) ?></div>
                    <div class="sub"><?= htmlspecialchars($j['sub']) ?></div>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="sec-label"><i class="bi bi-joystick"></i> Minigames</div>
            <div class="grid">
                <?php foreach ($jogosLivres as $j): ?>
                <a class="card-jogo" href="/games/games/index.php?game=<?= urlencode($j['key']) ?>">
                    <div class="ico" style="background:<?= $j['cor'] ?>1f;color:<?= $j['cor'] ?>"><i class="bi <?= $j['icone'] ?>"></i></div>
                    <div class="nome"><?= htmlspecialchars($j['nome']) ?></div>
                    <div class="sub"><?= htmlspecialchars($j['sub']) ?></div>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="sec-label"><i class="bi bi-collection-fill"></i> Coleção</div>
            <div class="grid">
                <a class="card-jogo" href="/games/album-fba.php">
                    <div class="ico" style="background:#f59e0b1f;color:#f59e0b"><i class="bi bi-images"></i></div>
                    <div class="nome">Álbum FBA</div>
                    <div class="sub">Figurinhas e mercado</div>
                </a>
                <a class="card-jogo" href="/games/user/ranking.php">
                    <div class="ico" style="background:#a855f71f;color:#a855f7"><i class="bi bi-bar-chart-fill"></i></div>
                    <div class="nome">Ranking</div>
                    <div class="sub">Quem lidera a liga</div>
                </a>
            </div>
        </div>

        <!-- ── Aba Apostas ───────────────────────────────────────────── -->
        <div class="g-pane <?= $abaInicial === 'apostas' ? 'active' : '' ?>" id="pane-apostas">
            <?php if ($apostaMsg): ?>
                <div class="alerta ok"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($apostaMsg) ?></div>
            <?php endif; ?>
            <?php if ($apostaErro): ?>
                <div class="alerta err"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($apostaErro) ?></div>
            <?php endif; ?>

            <div class="sec-label"><i class="bi bi-lightning-charge-fill"></i> Eventos abertos</div>
            <?php if (empty($eventos)): ?>
                <div class="card"><div class="vazio">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Nenhum evento aberto agora. Assim que a organização abrir um, ele aparece aqui.</p>
                </div></div>
            <?php else: ?>
                <?php foreach ($eventos as $ev): ?>
                <div class="card">
                    <div class="card-head">
                        <div class="card-head-left"><i class="bi bi-flag-fill"></i> <?= htmlspecialchars($ev['nome']) ?></div>
                        <div class="prazo">
                            <i class="bi bi-clock"></i>
                            até <?= date('d/m/Y H:i', strtotime($ev['data_limite'])) ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="opcoes">
                            <?php foreach ($ev['opcoes'] as $op): ?>
                            <form method="POST" style="flex:1;min-width:150px;display:flex">
                                <input type="hidden" name="opcao_id" value="<?= (int)$op['id'] ?>">
                                <button type="submit" class="op-btn <?= (int)$ev['meu_palpite'] === (int)$op['id'] ? 'escolhida' : '' ?>">
                                    <?= htmlspecialchars($op['descricao']) ?>
                                </button>
                            </form>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($ev['meu_palpite']): ?>
                        <div style="font-size:11.5px;color:var(--text-3);margin-top:11px">
                            <i class="bi bi-info-circle"></i> Dá pra trocar sua escolha até o prazo acabar.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="sec-label" style="margin-top:30px"><i class="bi bi-clock-history"></i> Meus palpites</div>
            <div class="card">
                <?php if (empty($historico)): ?>
                    <div class="vazio"><i class="bi bi-inbox"></i><p>Você ainda não deu nenhum palpite.</p></div>
                <?php else: ?>
                <div class="card-body tbl-wrap">
                    <table class="hist">
                        <thead><tr><th>Evento</th><th>Sua escolha</th><th>Quando</th><th>Resultado</th></tr></thead>
                        <tbody>
                        <?php foreach ($historico as $h): ?>
                            <tr>
                                <td><?= htmlspecialchars($h['evento']) ?></td>
                                <td style="color:var(--text-2)"><?= htmlspecialchars($h['escolha']) ?></td>
                                <td style="color:var(--text-3);font-size:12px"><?= date('d/m/Y', strtotime($h['data_palpite'])) ?></td>
                                <td>
                                    <?php if ($h['evento_status'] !== 'encerrada' || $h['vencedor_opcao_id'] === null): ?>
                                        <span class="pill aberta">Em aberto</span>
                                    <?php elseif ((int)$h['vencedor_opcao_id'] === (int)$h['opcao_id']): ?>
                                        <span class="pill acertou">Acertou</span>
                                    <?php else: ?>
                                        <span class="pill errou">Errou</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</main>
</div>

<script>
function trocarAba(aba) {
    document.querySelectorAll('.g-tab').forEach(t => t.classList.toggle('active', t.dataset.aba === aba));
    document.querySelectorAll('.g-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + aba));
    try { sessionStorage.setItem('gamesAba', aba); } catch (e) {}
    history.replaceState(null, '', aba === 'apostas' ? '?aba=apostas' : location.pathname);
}
(function () {
    // Só restaura a aba salva quando a URL não pediu uma explicitamente.
    if (!location.search.includes('aba=')) {
        try {
            const salva = sessionStorage.getItem('gamesAba');
            if (salva === 'apostas') trocarAba('apostas');
        } catch (e) {}
    }
    const menuBtn = document.getElementById('menuBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sbOverlay');
    menuBtn?.addEventListener('click', () => { sidebar?.classList.add('open'); overlay?.classList.add('open'); });
    overlay?.addEventListener('click', () => { sidebar?.classList.remove('open'); overlay?.classList.remove('open'); });
})();
</script>
</body>
</html>
