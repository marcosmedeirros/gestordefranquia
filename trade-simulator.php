<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

// ── API inline ────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

if ($action === 'teams') {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare('SELECT id, city, name, photo_url FROM teams WHERE league = ? ORDER BY name ASC');
    $stmt->execute([$user['league'] ?? '']);
    echo json_encode(['ok' => true, 'teams' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'roster') {
    header('Content-Type: application/json');
    $tid = (int)($_GET['team_id'] ?? 0);
    if (!$tid) { echo json_encode(['ok' => false]); exit; }

    $stmt = $pdo->prepare('SELECT id, city, name, photo_url FROM teams WHERE id = ? AND league = ?');
    $stmt->execute([$tid, $user['league'] ?? '']);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$team) { echo json_encode(['ok' => false]); exit; }

    $stmtP = $pdo->prepare('SELECT id, name, position, ovr, age FROM players WHERE team_id = ? ORDER BY ovr DESC, name ASC');
    $stmtP->execute([$tid]);
    $players = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $picks = [];
    try {
        $stmtPk = $pdo->prepare('
            SELECT pk.id, pk.season_year, pk.round,
                   ot.city AS orig_city, ot.name AS orig_name
            FROM picks pk
            LEFT JOIN teams ot ON pk.original_team_id = ot.id
            WHERE pk.team_id = ? AND pk.season_year >= YEAR(CURDATE())
            ORDER BY pk.round ASC, pk.season_year ASC
        ');
        $stmtPk->execute([$tid]);
        $picks = $stmtPk->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $cap = topEightCap($pdo, $tid);
    echo json_encode(['ok' => true, 'team' => $team, 'players' => $players, 'picks' => $picks, 'cap' => $cap]);
    exit;
}

// ── Page vars ─────────────────────────────────────────────────────────────────
$propose = !empty($_GET['propose']);   // show submit button

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$myTeam   = $stmtTeam->fetch() ?: null;
$myTeamId = $myTeam ? (int)$myTeam['id'] : 0;

$capMin = 0; $capMax = 0;
if ($myTeam) {
    try {
        $s = $pdo->prepare('SELECT cap_min, cap_max FROM league_settings WHERE league = ?');
        $s->execute([$myTeam['league'] ?? '']);
        $ls = $s->fetch();
        $capMin = (int)($ls['cap_min'] ?? 0);
        $capMax = (int)($ls['cap_max'] ?? 0);
    } catch (Exception $e) {}
}
$isAdmin = hasAdminAccess($pdo, (int)$user['id']);

$pageTitle = $propose ? 'Nova Trade' : 'Simulador de Trade';
$pageSub   = $propose ? 'Monte a troca com até 5 times e envie a proposta.' : 'Simule trocas sem enviar nada.';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<title><?= htmlspecialchars($pageTitle) ?> · FBA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/styles.css">
<style>
:root{
  --red:#fc0025;--red-soft:rgba(252,0,37,.10);--red-glow:rgba(252,0,37,.18);
  --bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
  --border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--border-red:rgba(252,0,37,.22);
  --text:#f0f0f3;--text-2:#868690;--text-3:#48484f;
  --green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;
  --sidebar-w:260px;--font:'Poppins',sans-serif;
  --radius:14px;--radius-sm:10px;--ease:cubic-bezier(.2,.8,.2,1);--t:200ms;
}
:root[data-theme="light"]{
  --bg:#f6f7fb;--panel:#fff;--panel-2:#f2f4f8;--panel-3:#e9edf4;
  --border:#e3e6ee;--border-md:#d7dbe6;--text:#111217;--text-2:#5b6270;--text-3:#8b93a5;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
body{overflow-x:hidden}
.app{display:flex;min-height:100vh}

/* Sidebar */
.sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;transition:transform var(--t) var(--ease);overflow-y:auto;scrollbar-width:none}
.sidebar::-webkit-scrollbar{display:none}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:299}
.sb-overlay.show{display:block}
.sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px}
.sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md)}
.sb-team-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.2}
.sb-team-league{font-size:11px;color:var(--red);font-weight:600}
.sb-nav{flex:1;padding:12px 10px 8px}
.sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 5px}
.sb-nav a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease)}
.sb-nav a i{font-size:15px;width:18px;text-align:center}
.sb-nav a:hover{background:var(--panel-2);color:var(--text)}
.sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600}
.sb-theme-toggle{margin:0 14px 12px;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--panel-2);color:var(--text);display:flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:600;cursor:pointer}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px}
.sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md)}
.sb-username{font-size:12px;font-weight:500;color:var(--text);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;text-decoration:none;transition:all var(--t) var(--ease)}
.sb-logout:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}

/* Topbar */
.topbar{position:fixed;top:0;left:0;right:0;z-index:240;background:var(--panel);border-bottom:1px solid var(--border);padding:0 16px;height:54px;display:none;align-items:center;gap:12px}
.topbar-menu-btn{background:none;border:none;color:var(--text-2);font-size:20px;cursor:pointer;padding:4px}
.topbar-title{font-size:14px;font-weight:600;color:var(--text)}

/* Main */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
.page-hero{padding:28px 28px 0;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px}
.page-hero-title{font-size:22px;font-weight:700;color:var(--text);line-height:1.2}
.content{padding:20px 28px 60px}

/* Buttons */
.btn-r{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;border:none;cursor:pointer;transition:opacity var(--t);font-family:var(--font)}
.btn-r:hover{opacity:.85}
.btn-r.primary{background:var(--red);color:#fff}
.btn-r.secondary{background:var(--panel-3);color:var(--text-2);border:1px solid var(--border)}
.btn-r.outline{background:transparent;color:var(--red);border:1px solid var(--border-red)}
.btn-r.green{background:rgba(34,197,94,.15);color:var(--green);border:1px solid rgba(34,197,94,.3)}
.btn-r:disabled{opacity:.35;cursor:not-allowed}
.btn-r.sm{padding:5px 12px;font-size:12px}
.btn-r.lg{padding:11px 24px;font-size:14px;font-weight:700}

/* Panels container — horizontal scroll for 3+ */
.sim-panels-wrap{display:flex;overflow-x:auto;border:1px solid var(--border);border-radius:var(--radius);background:var(--panel);-webkit-overflow-scrolling:touch;scrollbar-width:thin}
.sim-panels-wrap::-webkit-scrollbar{height:4px}
.sim-panels-wrap::-webkit-scrollbar-track{background:var(--panel-2)}
.sim-panels-wrap::-webkit-scrollbar-thumb{background:var(--border-md);border-radius:2px}

/* Each panel */
.sim-panel{display:flex;flex-direction:column;min-width:260px;flex:1;border-right:1px solid var(--border)}
.sim-panel:last-child{border-right:none}
.sim-panel-header{padding:14px 16px;border-bottom:1px solid var(--border);background:var(--panel-2);position:relative}
.sim-panel-close{position:absolute;top:10px;right:10px;background:none;border:none;color:var(--text-3);cursor:pointer;font-size:13px;padding:3px;transition:color .15s}
.sim-panel-close:hover{color:var(--red)}
.sim-team-logo{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0;background:var(--panel-3)}
.sim-team-name{font-size:13px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sim-team-cap{font-size:11px;color:var(--text-3);margin-top:1px}
.sim-team-select{background:var(--panel-3);border:1px solid var(--border-md);border-radius:8px;color:var(--text);font-size:12px;font-family:var(--font);padding:6px 10px;width:100%;margin-top:8px}
.sim-team-select:focus{outline:none;border-color:var(--red)}
.sim-label{font-size:9px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:10px 16px 6px}
.sim-items{flex:1;padding:0 10px 10px;display:flex;flex-direction:column;gap:5px;min-height:140px}
.sim-empty{display:flex;align-items:center;justify-content:center;height:70px;color:var(--text-3);font-size:12px;border:1px dashed var(--border);border-radius:8px;margin:4px 0}

/* Item card */
.sim-item{display:flex;align-items:center;gap:8px;padding:9px 10px;background:var(--panel-3);border:1px solid var(--border-md);border-radius:8px}
.sim-item-ovr{width:34px;height:34px;border-radius:7px;background:rgba(252,0,37,.15);border:1px solid rgba(252,0,37,.3);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:var(--red);flex-shrink:0}
.sim-item-pick-icon{width:34px;height:34px;border-radius:7px;background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.3);display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--blue);flex-shrink:0}
.sim-item-info{flex:1;min-width:0}
.sim-item-name{font-size:12px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sim-item-meta{font-size:10px;color:var(--text-2)}
.sim-item-from{font-size:10px;color:var(--blue);font-weight:600}
.sim-item-del{background:none;border:none;color:var(--text-3);cursor:pointer;font-size:13px;padding:3px;flex-shrink:0;transition:color .15s}
.sim-item-del:hover{color:var(--red)}
.sim-swap-select{background:var(--panel-2);border:1px solid var(--border-red);border-radius:5px;color:var(--red);font-size:10px;font-weight:700;padding:2px 5px;cursor:pointer;font-family:var(--font);flex-shrink:0}
.sim-swap-select:focus{outline:none}

/* Add buttons */
.sim-add-bar{padding:6px 10px 10px;display:flex;gap:6px;flex-wrap:wrap}
.btn-add{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:7px;color:var(--text-2);font-size:11px;font-weight:600;font-family:var(--font);cursor:pointer;transition:all .15s}
.btn-add:hover{border-color:var(--red);color:var(--red)}
.btn-add:disabled{opacity:.35;cursor:not-allowed}

/* Add team panel */
.sim-add-team-panel{display:flex;align-items:center;justify-content:center;padding:20px;min-width:80px;background:var(--panel-2)}
.btn-add-team{display:flex;flex-direction:column;align-items:center;gap:6px;background:none;border:1px dashed var(--border-md);border-radius:10px;color:var(--text-3);cursor:pointer;padding:16px 12px;font-family:var(--font);font-size:11px;font-weight:600;transition:all .15s;width:60px}
.btn-add-team:hover{border-color:var(--green);color:var(--green)}

/* CAP bar */
.cap-bar{display:flex;gap:1px;background:var(--border);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-top:12px;overflow-x:auto}
.cap-panel{background:var(--panel-2);padding:12px 14px;min-width:180px;flex:1}
.cap-label{font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cap-row{display:flex;justify-content:space-between;align-items:center;font-size:11px;color:var(--text-2);margin-bottom:3px}
.cap-val{font-weight:700;color:var(--text)}
.cap-val.ok{color:var(--green)}
.cap-val.warn{color:var(--amber)}
.cap-val.bad{color:var(--red)}

/* Bottom bar */
.sim-bottom{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:12px;padding:14px 16px;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm)}
.validity-badge{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.validity-badge.valid{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:var(--green)}
.validity-badge.warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:var(--amber)}
.validity-badge.neutral{background:var(--panel-2);border:1px solid var(--border);color:var(--text-3)}

/* Notes field */
.notes-wrap{margin-top:12px}
.notes-label{font-size:11px;font-weight:600;color:var(--text-2);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:5px}
.notes-input{width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;color:var(--text);font-family:var(--font);font-size:13px;padding:10px 12px;resize:vertical;min-height:64px}
.notes-input:focus{outline:none;border-color:var(--red)}

/* Picker modal */
.picker-list{max-height:340px;overflow-y:auto;display:flex;flex-direction:column;gap:3px}
.picker-row{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;cursor:pointer;transition:background .15s;border:1px solid transparent}
.picker-row:hover{background:var(--panel-2);border-color:var(--border-md)}
.picker-row.selected{background:rgba(252,0,37,.08);border-color:var(--border-red)}
.picker-ovr{width:32px;height:32px;border-radius:7px;background:var(--red-soft);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:var(--red);flex-shrink:0}
.picker-name{font-size:13px;font-weight:600;color:var(--text)}
.picker-meta{font-size:11px;color:var(--text-2)}
.picker-check{margin-left:auto;color:var(--green);font-size:16px;display:none}
.picker-row.selected .picker-check{display:block}
.from-team-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.from-chip{padding:5px 12px;border-radius:999px;border:1px solid var(--border-md);background:var(--panel-2);color:var(--text-2);font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;font-family:var(--font)}
.from-chip.active{background:rgba(59,130,246,.15);border-color:rgba(59,130,246,.4);color:var(--blue)}
.modal-content{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);color:var(--text)}
.modal-header{border-bottom:1px solid var(--border);padding:14px 18px}
.modal-title{font-size:14px;font-weight:600}
.modal-footer{border-top:1px solid var(--border);padding:12px 18px}
.modal-body{padding:14px 18px}
.btn-close-white{filter:invert(1) grayscale(100%) brightness(200%)}
.form-control{background:var(--panel-2);color:var(--text);border:1px solid var(--border);border-radius:8px;font-family:var(--font);font-size:13px}
.form-control:focus{background:var(--panel-2);color:var(--text);border-color:var(--red);box-shadow:0 0 0 3px var(--red-glow)}
.form-control::placeholder{color:var(--text-3)}
.btn-reset{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;border:1px solid var(--border);background:var(--panel-2);color:var(--text-3);font-size:12px;font-weight:600;cursor:pointer;font-family:var(--font);transition:all .15s}
.btn-reset:hover{border-color:var(--border-md);color:var(--text)}

@media(max-width:992px){
  :root{--sidebar-w:0px}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;padding-top:54px}
  .topbar{display:flex}
  .page-hero{padding:14px 16px 0}
  .content{padding:14px 16px 100px}
  .sim-panel{min-width:220px}
  .sim-team-select{font-size:15px;padding:9px 10px}
}

/* ── Mobile: redesign completo ───────────────────────────── */
@media(max-width:600px){
  /* Trava largura no viewport */
  html,body{overflow-x:hidden;max-width:100vw}
  .app,.main,.content{overflow-x:hidden;max-width:100vw}

  /* Título já aparece na topbar — oculta o hero inteiro */
  .page-hero{display:none}

  /* Conteúdo ocupa tudo; espaço inferior para bottom bar fixa */
  .content{padding:10px 0 140px}

  /* Wrap: empilha verticalmente */
  .sim-panels-wrap{
    flex-direction:column;
    overflow-x:visible;
    border-radius:0;
    border-left:none;border-right:none;
    gap:0;
  }

  /* Cada painel: largura 100% */
  .sim-panel{
    min-width:100%;
    border-right:none;
    border-bottom:1px solid var(--border);
  }
  .sim-panel:last-child{border-bottom:none}

  .sim-panel-header{padding:12px 14px}
  .sim-team-logo{width:36px;height:36px}
  .sim-team-name{font-size:14px}
  .sim-team-cap{font-size:11px}
  .sim-team-select{font-size:15px;padding:10px 12px;margin-top:10px}

  .sim-label{padding:10px 14px 5px;font-size:9px}
  .sim-items{min-height:56px;padding:0 12px 10px}
  .sim-empty{height:56px;font-size:12px}

  .sim-item{padding:10px 12px;gap:8px}
  .sim-item-ovr{width:32px;height:32px;font-size:11px}
  .sim-item-pick-icon{width:32px;height:32px;font-size:13px}
  .sim-item-name{font-size:13px}
  .sim-item-meta,.sim-item-from{font-size:10px}
  .sim-item-del{font-size:15px;padding:6px}

  .sim-add-bar{padding:8px 12px 12px;gap:8px}
  .btn-add{padding:9px 14px;font-size:12px;min-height:40px;flex:1;justify-content:center}

  /* Botão "+ Time" — linha separada abaixo dos painéis */
  .sim-add-team-panel{
    min-width:100%;
    padding:14px 12px;
    border-top:1px solid var(--border);
  }
  .btn-add-team{
    width:100%;flex-direction:row;gap:8px;
    border-radius:8px;padding:12px 16px;
    font-size:13px;justify-content:center;
  }

  /* CAP bar: scroll interno, não estoura a tela */
  .cap-bar{margin:10px 12px 0;border-radius:8px;max-width:calc(100vw - 24px);overflow-x:auto}
  .cap-panel{min-width:110px;padding:10px 12px}
  .cap-label{font-size:9px;margin-bottom:4px}
  .cap-row{font-size:10px;margin-bottom:2px}
  .cap-val{font-size:11px}

  /* Bottom bar: fixa na base, botões centralizados */
  .sim-bottom{
    position:fixed;bottom:0;left:0;right:0;z-index:200;
    flex-direction:column;align-items:stretch;
    gap:0;padding:10px 14px 14px;
    border-radius:0;border-left:none;border-right:none;border-bottom:none;
    background:var(--panel);
    box-shadow:0 -2px 16px rgba(0,0,0,.4);
    max-width:100vw;width:100vw;box-sizing:border-box;overflow:hidden;
  }
  #validityBadge{display:none}
  .sim-bottom>div{display:flex;gap:8px;width:100%}
  .sim-bottom .btn-r.lg{flex:1;justify-content:center;padding:13px 12px;font-size:14px}
  .sim-bottom .btn-r.secondary.sm{white-space:nowrap;padding:13px 14px;font-size:13px}

  /* Notes */
  .notes-wrap{margin:10px 12px 0}

  /* Picker modal — tela cheia */
  .modal-dialog{margin:0!important;max-width:100%!important;width:100%!important}
  .modal-content{border-radius:0!important;height:100dvh;display:flex;flex-direction:column}
  .modal-header{flex-shrink:0;padding:14px 16px}
  .modal-body{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:12px 16px}
  .modal-footer{
    flex-shrink:0;
    padding:10px 14px 14px;
    border-top:1px solid var(--border);
    background:var(--panel);
    box-shadow:0 -2px 12px rgba(0,0,0,.35);
    justify-content:stretch!important;
    gap:8px;
  }
  .modal-footer>span{display:none} /* oculta hint no mobile */
  .modal-footer>div{display:flex;gap:8px;width:100%}
  .modal-footer .btn-r{flex:1;justify-content:center;padding:13px 12px;font-size:14px}
  .picker-list{max-height:none}
  .picker-row{padding:14px 12px;min-height:52px;gap:12px}
  .picker-name{font-size:14px}
  .picker-meta{font-size:12px}
  .from-chip{padding:9px 16px;min-height:40px;font-size:12px}
  .form-control{font-size:16px!important} /* evita zoom no iOS */
}
</style>
</head>
<body>
<div class="app">

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <?php if ($myTeam): ?>
  <div class="sb-team">
    <img src="<?= htmlspecialchars(getTeamPhoto($myTeam['photo_url'] ?? null)) ?>" alt="" onerror="this.src='/img/default-team.png'">
    <div>
      <div class="sb-team-name"><?= htmlspecialchars(trim(($myTeam['city'] ?? '') . ' ' . ($myTeam['name'] ?? ''))) ?></div>
      <div class="sb-team-league"><?= htmlspecialchars($myTeam['league'] ?? '') ?></div>
    </div>
  </div>
  <?php endif; ?>
  <nav class="sb-nav">
    <div class="sb-section">Principal</div>
    <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
    <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
    <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
    <a href="/players.php"><i class="bi bi-person-lines-fill"></i> Jogadores</a>
    <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
    <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
    <a href="/mercado.php"><i class="bi bi-shop"></i> Mercado</a>
    <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
    <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
    <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>
    <a href="/tapas.php"><i class="bi bi-hand-index-thumb"></i> Tapas</a>
    <div class="sb-section">Liga</div>
    <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
    <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
    <a href="/hall-da-fama.php"><i class="bi bi-award-fill"></i> Hall da Fama</a>
    <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
    <a href="/mundo-fba.php"><i class="bi bi-globe2"></i> Mundo FBA</a>
    <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
    <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>
    <a href="/thepathetic.php"><i class="bi bi-newspaper"></i> The Pathetic</a>
    <?php if ($isAdmin): ?>
    <div class="sb-section">Admin</div>
    <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
    <?php endif; ?>
    <div class="sb-section">Conta</div>
    <a href="/settings.php"><i class="bi bi-gear-fill"></i> Minha Conta</a>
  </nav>
  <button class="sb-theme-toggle" type="button" id="themeToggle" data-theme-toggle>
    <i class="bi bi-moon"></i><span>Modo escuro</span>
  </button>
  <div class="sb-footer">
    <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>" alt="" class="sb-avatar"
         onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name'] ?? 'U') ?>&background=1c1c21&color=fc0025'">
    <span class="sb-username"><?= htmlspecialchars($user['name'] ?? '') ?></span>
    <a href="/logout.php" class="sb-logout"><i class="bi bi-box-arrow-right"></i></a>
  </div>
</aside>
<div class="sb-overlay" id="sbOverlay"></div>

<main class="main">
  <!-- Topbar -->
  <div class="topbar">
    <button class="topbar-menu-btn" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <span class="topbar-title"><i class="bi bi-arrow-left-right me-2" style="color:var(--red)"></i><?= htmlspecialchars($pageTitle) ?></span>
  </div>

  <!-- Hero -->
  <div class="page-hero">
    <div>
      <h1 class="page-hero-title"><i class="bi bi-arrow-left-right me-2" style="color:var(--red)"></i><?= htmlspecialchars($pageTitle) ?></h1>
      <p style="font-size:13px;color:var(--text-2);margin-top:4px"><?= htmlspecialchars($pageSub) ?></p>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <button class="btn-reset" onclick="resetAll()"><i class="bi bi-arrow-counterclockwise"></i>Limpar</button>
      <a href="/trades.php" class="btn-r secondary sm" style="text-decoration:none"><i class="bi bi-arrow-left"></i>Trades</a>
    </div>
  </div>

  <div class="content">

    <!-- Panels -->
    <div class="sim-panels-wrap" id="panelsWrap"></div>

    <!-- CAP impact -->
    <div class="cap-bar" id="capBar" style="display:none"></div>

    <!-- Bottom bar -->
    <div class="sim-bottom">
      <div id="validityBadge" class="validity-badge neutral">
        <i class="bi bi-hourglass-split"></i>AGUARDANDO
      </div>
      <?php if ($propose): ?>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <button class="btn-r secondary sm" onclick="resetAll()"><i class="bi bi-x-lg"></i>Limpar</button>
        <button class="btn-r secondary sm" id="copyTradeBtn" onclick="copyTrade()"><i class="bi bi-clipboard"></i>Copiar</button>
        <button class="btn-r primary lg" id="submitBtn" onclick="submitTrade()" disabled>
          <i class="bi bi-send-fill"></i>Enviar Proposta
        </button>
      </div>
      <?php else: ?>
      <div style="display:flex;gap:8px">
        <button class="btn-r secondary sm" id="copyTradeBtn" onclick="copyTrade()"><i class="bi bi-clipboard"></i>Copiar</button>
        <a href="/trade-simulator.php?propose=1" class="btn-r outline sm" style="text-decoration:none"><i class="bi bi-send"></i>Propor esta trade</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Notes (propose mode only) -->
    <?php if ($propose): ?>
    <div class="notes-wrap">
      <label class="notes-label">Mensagem (opcional)</label>
      <textarea class="notes-input" id="tradeNotes" placeholder="Adicione uma mensagem para a proposta..."></textarea>
    </div>
    <?php endif; ?>

  </div>
</main>
</div>

<!-- Picker Modal -->
<div class="modal fade" id="pickerModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:460px">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="pickerTitle">Selecionar</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- From-team selector (multi mode only) -->
        <div id="fromTeamWrap" style="margin-bottom:10px;display:none">
          <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.06em">De qual time?</div>
          <div class="from-team-chips" id="fromTeamChips"></div>
        </div>
        <input type="text" class="form-control mb-2" id="pickerSearch" placeholder="Buscar..." oninput="filterPicker()" style="font-size:13px">
        <div class="picker-list" id="pickerList"></div>
      </div>
      <div class="modal-footer" style="justify-content:space-between">
        <span id="pickerHint" style="font-size:11px;color:var(--text-3)"></span>
        <div style="display:flex;gap:8px">
          <button class="btn-r secondary sm" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn-r primary sm" onclick="confirmPicker()"><i class="bi bi-check-lg"></i>Adicionar</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const IS_PROPOSE = <?= $propose ? 'true' : 'false' ?>;
const CAP_MIN    = <?= $capMin ?>;
const CAP_MAX    = <?= $capMax ?>;
const MY_TEAM_ID = <?= $myTeamId ?>;
const MAX_TEAMS  = 5;

const SLOT_KEYS = ['A','B','C','D','E'];

// State
const teams   = {}; // key → { id, name, photo_url, players, picks, cap, tradedOut }
const receives = {}; // key → Array of items this team receives
let activeSlots = ['A', 'B'];

// Picker state
let pickerToSlot   = 'A';   // which panel receives
let pickerFromSlot = 'B';   // which team sends (single) or selected from-chip (multi)
let pickerType     = 'player';

// ── Boot ──────────────────────────────────────────────────────────────────────
async function boot() {
  // Init slots
  activeSlots.forEach(k => { teams[k] = null; receives[k] = []; });
  renderPanels();

  const r = await fetch('/trade-simulator.php?action=teams');
  const d = await r.json();
  if (!d.ok) return;
  window._allTeams = d.teams;

  // Populate selectors
  activeSlots.forEach(k => populateTeamSelect(k, d.teams));

  // Pre-load my team in A
  if (MY_TEAM_ID) {
    const sel = document.getElementById(`sel_${activeSlots[0]}`);
    if (sel) { sel.value = MY_TEAM_ID; loadTeam(activeSlots[0], MY_TEAM_ID); }
  }
}

function populateTeamSelect(key, teamsList) {
  const sel = document.getElementById(`sel_${key}`);
  if (!sel) return;
  const cur = sel.value;
  sel.innerHTML = '<option value="">Selecionar time...</option>';
  teamsList.forEach(t => {
    const opt = document.createElement('option');
    opt.value = t.id;
    opt.textContent = `${t.city ?? ''} ${t.name ?? ''}`.trim();
    sel.appendChild(opt);
  });
  if (cur) sel.value = cur;
}

// ── Team loading ──────────────────────────────────────────────────────────────
async function loadTeam(key, teamId) {
  if (!teamId) {
    teams[key] = null;
    receives[key] = [];
    renderPanel(key);
    recalc();
    return;
  }
  const r = await fetch(`/trade-simulator.php?action=roster&team_id=${teamId}`);
  const d = await r.json();
  if (!d.ok) return;

  teams[key] = {
    id: +teamId,
    name: `${d.team.city ?? ''} ${d.team.name ?? ''}`.trim(),
    photo_url: d.team.photo_url || '',
    players: d.players,
    picks:   d.picks,
    cap:     d.cap,
    tradedOut: new Set(),
  };
  receives[key] = [];
  renderPanel(key);
  recalc();
}

// ── Panel rendering ───────────────────────────────────────────────────────────
function renderPanels() {
  const wrap = document.getElementById('panelsWrap');
  wrap.innerHTML = '';
  activeSlots.forEach(k => {
    wrap.appendChild(buildPanel(k));
  });
  if (activeSlots.length < MAX_TEAMS) {
    const addDiv = document.createElement('div');
    addDiv.className = 'sim-add-team-panel';
    addDiv.innerHTML = `<button class="btn-add-team" onclick="addTeamSlot()" title="Adicionar time">
      <i class="bi bi-plus-lg" style="font-size:18px"></i>Time
    </button>`;
    wrap.appendChild(addDiv);
  }
}

function buildPanel(key) {
  const t = teams[key];
  const items = receives[key] || [];
  const canClose = activeSlots.length > 2 && (!t || t.id !== MY_TEAM_ID);

  const div = document.createElement('div');
  div.className = 'sim-panel';
  div.id = `panel_${key}`;

  const hasTeam = !!t;
  const logoHtml = t?.photo_url
    ? `<img class="sim-team-logo" src="${escA(t.photo_url)}" onerror="this.style.display='none'" alt="">`
    : `<div class="sim-team-logo" style="background:var(--panel-3)"></div>`;

  const isMyTeam = t && t.id === MY_TEAM_ID;
  const singleFixed = false;
  const selectDisabled = singleFixed ? 'disabled' : '';

  div.innerHTML = `
    <div class="sim-panel-header">
      ${canClose ? `<button class="sim-panel-close" onclick="removeTeamSlot('${key}')" title="Remover"><i class="bi bi-x-lg"></i></button>` : ''}
      <div style="display:flex;align-items:center;gap:10px">
        ${logoHtml}
        <div style="flex:1;min-width:0">
          <div class="sim-team-name">${t ? escH(t.name) : '<span style="color:var(--text-3)">Selecionar time</span>'}</div>
          <div class="sim-team-cap" id="capInfo_${key}">${t ? `CAP: ${t.cap}` : ''}</div>
        </div>
      </div>
      <select class="sim-team-select" id="sel_${key}" ${selectDisabled}
        onchange="loadTeam('${key}', this.value)">
        <option value="">Selecionar time...</option>
      </select>
    </div>
    <div class="sim-label">Recebe</div>
    <div class="sim-items" id="items_${key}">
      ${items.length ? items.map(item => itemHtml(item, key)).join('') : '<div class="sim-empty">Nenhum item</div>'}
    </div>
    <div class="sim-add-bar">
      <button class="btn-add" id="addP_${key}" ${!hasTeam ? 'disabled' : ''} onclick="openPicker('${key}','player')">
        <i class="bi bi-person-plus-fill"></i>Jogador
      </button>
      <button class="btn-add" id="addPk_${key}" ${!hasTeam ? 'disabled' : ''} onclick="openPicker('${key}','pick')">
        <i class="bi bi-calendar-plus"></i>Pick
      </button>
    </div>
  `;

  return div;
}

function renderPanel(key) {
  const existing = document.getElementById(`panel_${key}`);
  if (!existing) return;
  const newPanel = buildPanel(key);
  existing.replaceWith(newPanel);
  // Re-populate team select
  if (window._allTeams) populateTeamSelect(key, window._allTeams);
  const t = teams[key];
  if (t) {
    const sel = document.getElementById(`sel_${key}`);
    if (sel) sel.value = t.id;
  }
}

function itemHtml(item, toKey) {
  const fromName = teams[item.fromKey]?.name ?? '?';
  if (item.type === 'player') {
    return `<div class="sim-item">
      <div class="sim-item-ovr">${item.ovr}</div>
      <div class="sim-item-info">
        <div class="sim-item-name">${escH(item.name)}</div>
        <div class="sim-item-meta">${item.pos} · ${item.age}a · OVR ${item.ovr}</div>
        <div class="sim-item-from">← ${escH(fromName)}</div>
      </div>
      <button class="sim-item-del" onclick="removeItem('${toKey}',${item.id},'player','${item.fromKey}')" title="Remover"><i class="bi bi-x-lg"></i></button>
    </div>`;
  } else {
    const pair = findSwapPair(toKey, item);
    const swapSel = pair
      ? `<select class="sim-swap-select" onchange="setSimSwapRole('${toKey}',${item.id},this.value)" title="Swap">
           <option value="" ${!item.swapRole ? 'selected' : ''}>—</option>
           <option value="SB" ${item.swapRole === 'SB' ? 'selected' : ''}>SB</option>
           <option value="SW" ${item.swapRole === 'SW' ? 'selected' : ''}>SW</option>
         </select>`
      : '';
    return `<div class="sim-item">
      <div class="sim-item-pick-icon"><i class="bi bi-calendar-event"></i></div>
      <div class="sim-item-info">
        <div class="sim-item-name">${escH(item.label)}${item.swapRole ? ` <span style="color:var(--red);font-size:9px;font-weight:700">${item.swapRole}</span>` : ''}</div>
        <div class="sim-item-meta">${escH(item.orig)}</div>
        <div class="sim-item-from">← ${escH(fromName)}</div>
      </div>
      ${swapSel}
      <button class="sim-item-del" onclick="removeItem('${toKey}',${item.id},'pick','${item.fromKey}')" title="Remover"><i class="bi bi-x-lg"></i></button>
    </div>`;
  }
}

// ── Picker ────────────────────────────────────────────────────────────────────
function openPicker(toKey, type) {
  pickerToSlot = toKey;
  pickerType   = type;

  // Default from-slot: the other active slot (or A for multi)
  pickerFromSlot = activeSlots.find(k => k !== toKey && teams[k]) || null;

  document.getElementById('pickerSearch').value = '';

  const toTeamName = teams[toKey]?.name ?? 'time';
  document.getElementById('pickerTitle').textContent =
    type === 'player' ? `Selecionar jogador → ${toTeamName}` : `Selecionar pick → ${toTeamName}`;

  document.getElementById('pickerHint').textContent =
    type === 'player' ? 'Selecione jogadores que serão enviados' : 'Selecione picks a enviar';

  // Mostrar chips dos times que podem enviar
  const fromWrap = document.getElementById('fromTeamWrap');
  fromWrap.style.display = '';
  const chips = document.getElementById('fromTeamChips');
  chips.innerHTML = '';
  activeSlots.filter(k => k !== toKey && teams[k]).forEach(k => {
    const btn = document.createElement('button');
    btn.className = 'from-chip' + (k === pickerFromSlot ? ' active' : '');
    btn.textContent = teams[k].name;
    btn.onclick = () => {
      pickerFromSlot = k;
      document.querySelectorAll('.from-chip').forEach(c => c.classList.remove('active'));
      btn.classList.add('active');
      renderPickerList();
    };
    chips.appendChild(btn);
  });

  renderPickerList();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('pickerModal')).show();
}

function renderPickerList() {
  if (!pickerFromSlot || !teams[pickerFromSlot]) {
    document.getElementById('pickerList').innerHTML = '<div style="text-align:center;padding:24px;color:var(--text-3);font-size:12px">Selecione um time para continuar.</div>';
    return;
  }
  const src = teams[pickerFromSlot];
  const alreadyIn = new Set(
    receives[pickerToSlot].filter(i => i.type === pickerType && i.fromKey === pickerFromSlot).map(i => i.id)
  );

  if (pickerType === 'player') {
    document.getElementById('pickerList').innerHTML = src.players.length
      ? src.players.map(p => `
          <div class="picker-row${alreadyIn.has(p.id) ? ' selected' : ''}" data-id="${p.id}" onclick="togglePick(this)">
            <div class="picker-ovr">${p.ovr}</div>
            <div>
              <div class="picker-name">${escH(p.name)}</div>
              <div class="picker-meta">${p.position} · ${p.age} anos · OVR ${p.ovr}</div>
            </div>
            <i class="bi bi-check2-circle picker-check"></i>
          </div>`).join('')
      : '<div style="text-align:center;padding:24px;color:var(--text-3);font-size:12px">Sem jogadores</div>';
  } else {
    const currentYear = new Date().getFullYear();
    const visiblePicks = src.picks.filter(p => (Number(p.season_year) || 0) >= currentYear);
    document.getElementById('pickerList').innerHTML = visiblePicks.length
      ? visiblePicks.map(p => {
          const label = pickLabel(p);
          return `<div class="picker-row${alreadyIn.has(p.id) ? ' selected' : ''}" data-id="${p.id}" onclick="togglePick(this)">
            <div class="picker-ovr" style="background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.3);color:var(--blue)">
              <i class="bi bi-calendar-event" style="font-size:13px"></i>
            </div>
            <div>
              <div class="picker-name">${escH(label)}</div>
              <div class="picker-meta">${(p.orig_city ?? '') + ' ' + (p.orig_name ?? '')}</div>
            </div>
            <i class="bi bi-check2-circle picker-check"></i>
          </div>`;
        }).join('')
      : '<div style="text-align:center;padding:24px;color:var(--text-3);font-size:12px">Sem picks</div>';
  }
}

function togglePick(el) {
  el.classList.toggle('selected');
}

function filterPicker() {
  const q = document.getElementById('pickerSearch').value.toLowerCase();
  document.querySelectorAll('#pickerList .picker-row').forEach(row => {
    const name = row.querySelector('.picker-name')?.textContent.toLowerCase() ?? '';
    row.style.display = name.includes(q) ? '' : 'none';
  });
}

function confirmPicker() {
  if (!pickerFromSlot || !teams[pickerFromSlot]) {
    bootstrap.Modal.getInstance(document.getElementById('pickerModal')).hide();
    return;
  }
  const src = teams[pickerFromSlot];

  document.querySelectorAll('#pickerList .picker-row.selected').forEach(row => {
    const id = +row.dataset.id;
    // Don't add duplicates from same fromKey
    if (receives[pickerToSlot].find(i => i.id === id && i.type === pickerType && i.fromKey === pickerFromSlot)) return;

    if (pickerType === 'player') {
      const p = src.players.find(pl => pl.id === id);
      if (!p) return;
      receives[pickerToSlot].push({ id, type: 'player', fromKey: pickerFromSlot, name: p.name, pos: p.position, age: p.age, ovr: p.ovr });
      teams[pickerFromSlot].tradedOut.add(id);
    } else {
      const pk = src.picks.find(pk => pk.id === id);
      if (!pk) return;
      receives[pickerToSlot].push({ id, type: 'pick', fromKey: pickerFromSlot, label: pickLabel(pk), orig: `${pk.orig_city ?? ''} ${pk.orig_name ?? ''}`.trim(), round: pk.round, season_year: pk.season_year, swapRole: null });
    }
  });

  renderPanel(pickerToSlot);
  if (window._allTeams) populateTeamSelect(pickerToSlot, window._allTeams);
  const t = teams[pickerToSlot];
  if (t) { const sel = document.getElementById(`sel_${pickerToSlot}`); if (sel) sel.value = t.id; }

  recalc();
  bootstrap.Modal.getInstance(document.getElementById('pickerModal')).hide();
}

function removeItem(toKey, id, type, fromKey) {
  receives[toKey] = receives[toKey].filter(i => !(i.id === id && i.type === type && i.fromKey === fromKey));
  if (type === 'player' && teams[fromKey]) teams[fromKey].tradedOut.delete(id);
  renderPanel(toKey);
  if (window._allTeams) populateTeamSelect(toKey, window._allTeams);
  const t = teams[toKey];
  if (t) { const sel = document.getElementById(`sel_${toKey}`); if (sel) sel.value = t.id; }
  recalc();
}

// ── Multi team management ─────────────────────────────────────────────────────
function addTeamSlot() {
  const used = new Set(activeSlots);
  const next = SLOT_KEYS.find(k => !used.has(k));
  if (!next || activeSlots.length >= MAX_TEAMS) return;
  activeSlots.push(next);
  teams[next] = null;
  receives[next] = [];
  renderPanels();
  if (window._allTeams) activeSlots.forEach(k => populateTeamSelect(k, window._allTeams));
  // Restore existing team selections
  activeSlots.forEach(k => {
    if (teams[k]) { const sel = document.getElementById(`sel_${k}`); if (sel) sel.value = teams[k].id; }
  });
}

function removeTeamSlot(key) {
  if (activeSlots.length <= 2) return;
  // Remove items from other panels that came from this slot
  activeSlots.forEach(k => {
    if (k === key) return;
    receives[k] = receives[k].filter(i => i.fromKey !== key);
  });
  activeSlots = activeSlots.filter(k => k !== key);
  delete teams[key];
  delete receives[key];
  renderPanels();
  if (window._allTeams) activeSlots.forEach(k => populateTeamSelect(k, window._allTeams));
  activeSlots.forEach(k => {
    if (teams[k]) { const sel = document.getElementById(`sel_${k}`); if (sel) sel.value = teams[k].id; }
  });
  recalc();
}

// ── CAP Recalc ────────────────────────────────────────────────────────────────
function simTop8(key) {
  const t = teams[key];
  if (!t) return 0;
  const tradedOut = t.tradedOut;
  const keep = t.players.filter(p => !tradedOut.has(p.id));
  // Add incoming players (those in receives[key])
  const incoming = receives[key].filter(i => i.type === 'player');
  const all = [...keep, ...incoming];
  all.sort((a, b) => b.ovr - a.ovr);
  return all.slice(0, 8).reduce((s, p) => s + (+p.ovr), 0);
}

function recalc() {
  const hasAny = activeSlots.some(k => teams[k]);
  const hasItems = activeSlots.some(k => (receives[k] || []).length > 0);

  // CAP bar
  const capBar = document.getElementById('capBar');
  if (!hasAny) { capBar.style.display = 'none'; updateValidity('neutral','AGUARDANDO'); return; }
  capBar.style.display = '';

  capBar.innerHTML = '';
  let anyInvalid = false;

  activeSlots.forEach(key => {
    const t = teams[key];
    if (!t) return;
    const newCap = simTop8(key);
    const delta  = newCap - t.cap;
    const totalAfter = t.players.filter(p => !t.tradedOut.has(p.id)).length + receives[key].filter(i => i.type === 'player').length;
    const cls = capClass(newCap);
    if (cls === 'bad' || cls === 'warn') anyInvalid = true;

    // Update cap info in panel header
    const ci = document.getElementById(`capInfo_${key}`);
    if (ci) ci.textContent = hasItems ? `CAP: ${t.cap} → ${newCap}${delta !== 0 ? ` (${delta > 0 ? '+' : ''}${delta})` : ''}` : `CAP: ${t.cap}`;

    const panel = document.createElement('div');
    panel.className = 'cap-panel';
    panel.innerHTML = `
      <div class="cap-label">${escH(t.name)}</div>
      <div class="cap-row"><span>Atual</span><span class="cap-val">${t.cap}</span></div>
      <div class="cap-row"><span>Pós-trade</span><span class="cap-val ${cls}">${newCap}</span></div>
      <div class="cap-row"><span>Jogadores</span><span class="cap-val">${t.players.filter(p=>!t.tradedOut.has(p.id)).length} → ${totalAfter}</span></div>
      ${CAP_MAX > 0 ? `<div class="cap-row"><span style="font-size:10px">Limite ${CAP_MIN}–${CAP_MAX}</span><span class="cap-val ${cls}" style="font-size:10px">${capStatus(newCap)}</span></div>` : ''}
    `;
    capBar.appendChild(panel);
  });

  if (!hasItems) { updateValidity('neutral','AGUARDANDO'); return; }
  updateValidity(anyInvalid ? 'warn' : 'valid', anyInvalid ? 'CAP ALTERADO' : 'OK');

  if (IS_PROPOSE) {
    const btn = document.getElementById('submitBtn');
    if (btn) btn.disabled = !canSubmit();
  }
}

function canSubmit() {
  const loadedTeams = activeSlots.filter(k => teams[k]).length;
  const hasItems = activeSlots.some(k => (receives[k] || []).length > 0);
  return loadedTeams >= 2 && hasItems;
}

function updateValidity(cls, text) {
  const el = document.getElementById('validityBadge');
  el.className = `validity-badge ${cls}`;
  const icon = cls === 'valid' ? 'check-circle-fill' : cls === 'invalid' ? 'x-circle-fill' : 'hourglass-split';
  el.innerHTML = `<i class="bi bi-${icon}"></i>${text}`;
}

function capClass(cap) {
  if (!CAP_MAX) return '';
  if (cap > CAP_MAX) return 'bad';
  if (CAP_MIN > 0 && cap < CAP_MIN) return 'warn';
  return 'ok';
}
function capStatus(cap) {
  if (!CAP_MAX) return '—';
  if (cap > CAP_MAX) return '⚠ Acima';
  if (CAP_MIN > 0 && cap < CAP_MIN) return '⚠ Abaixo';
  return '✓ OK';
}

// ── Submit ────────────────────────────────────────────────────────────────────
async function submitTrade() {
  if (!canSubmit()) { alert('Complete a trade antes de enviar.'); return; }
  const notes = document.getElementById('tradeNotes')?.value || '';
  const btn = document.getElementById('submitBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i>Enviando...'; }

  const loadedCount = activeSlots.filter(k => teams[k]).length;
  try {
    if (loadedCount > 2) {
      await submitMultiTrade(notes);
    } else {
      await submitSingleTrade(notes);
    }
  } catch(e) {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill"></i>Enviar Proposta'; }
    alert(e.error || e.message || 'Erro ao enviar trade.');
  }
}

async function submitSingleTrade(notes) {
  const [kA, kB] = activeSlots;
  const tA = teams[kA];
  const tB = teams[kB];

  // receiveA = what A gets from B = A's request = B's offer
  const offerPlayers  = receives[kB].filter(i => i.type === 'player').map(i => i.id);
  const offerPicks    = receives[kB].filter(i => i.type === 'pick').map(i => ({ id: i.id }));
  const requestPlayers = receives[kA].filter(i => i.type === 'player').map(i => i.id);
  const requestPicks   = receives[kA].filter(i => i.type === 'pick').map(i => ({ id: i.id }));

  // Monta swap_pairs: picks de lados opostos, mesma rodada, anos diferentes
  const swapPairs = [];
  const usedSwap = new Set();
  receives[kB].forEach(item => {
    if (item.type !== 'pick' || !item.swapRole || usedSwap.has(item.id)) return;
    const pair = findSwapPair(kB, item);
    if (!pair || !pair.item.swapRole) return;
    swapPairs.push({
      offer_pick_id: item.id,
      request_pick_id: pair.item.id,
      offer_role: item.swapRole,
      request_role: pair.item.swapRole,
    });
    usedSwap.add(item.id);
    usedSwap.add(pair.item.id);
  });

  const payload = {
    to_team_id: tB.id,
    offer_players: offerPlayers,
    offer_picks: offerPicks,
    request_players: requestPlayers,
    request_picks: requestPicks,
    swap_pairs: swapPairs,
    notes,
  };

  const r = await fetch('/api/trades.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const d = await r.json();
  if (!r.ok || d.success === false) throw d;

  alert('Proposta enviada com sucesso!');
  if (window.self !== window.top) {
    window.parent.postMessage({ type: 'trade-submitted' }, '*');
  } else {
    window.location.href = '/trades.php';
  }
}

async function submitMultiTrade(notes) {
  const teamIds = activeSlots.filter(k => teams[k]).map(k => teams[k].id);
  const items = [];
  activeSlots.forEach(toKey => {
    (receives[toKey] || []).forEach(item => {
      const fromTeam = teams[item.fromKey];
      const toTeam   = teams[toKey];
      if (!fromTeam || !toTeam) return;
      const entry = { from_team_id: fromTeam.id, to_team_id: toTeam.id };
      if (item.type === 'player') entry.player_id = item.id;
      else entry.pick_id = item.id;
      items.push(entry);
    });
  });

  const r = await fetch('/api/trades.php?action=multi_trades', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ teams: teamIds, items, notes }),
  });
  const d = await r.json();
  if (!r.ok || d.success === false) throw d;

  alert('Trade múltipla enviada!');
  if (window.self !== window.top) {
    window.parent.postMessage({ type: 'trade-submitted' }, '*');
  } else {
    window.location.href = '/trades.php';
  }
}

// ── Reset ─────────────────────────────────────────────────────────────────────
function resetAll() {
  activeSlots.forEach(k => {
    receives[k] = [];
    if (teams[k]) teams[k].tradedOut = new Set();
    renderPanel(k);
    if (window._allTeams) populateTeamSelect(k, window._allTeams);
    if (teams[k]) { const s = document.getElementById(`sel_${k}`); if (s) s.value = teams[k].id; }
  });
  recalc();
}

// ── Swap helpers ──────────────────────────────────────────────────────────────
function findSwapPair(toKey, item) {
  for (const otherToKey of activeSlots) {
    if (otherToKey === toKey) continue;
    for (const other of (receives[otherToKey] || [])) {
      if (other.type !== 'pick') continue;
      if (other.fromKey !== toKey) continue;       // vem deste time
      if (item.fromKey !== otherToKey) continue;   // vai para o outro time
      if (String(other.round) !== String(item.round)) continue;
      if (String(other.season_year) === String(item.season_year)) continue;
      return { toKey: otherToKey, item: other };
    }
  }
  return null;
}

function setSimSwapRole(toKey, itemId, role) {
  const item = (receives[toKey] || []).find(i => i.id === itemId && i.type === 'pick');
  if (!item) return;
  item.swapRole = role || null;
  const pair = findSwapPair(toKey, item);
  if (pair) {
    pair.item.swapRole = role ? (role === 'SB' ? 'SW' : 'SB') : null;
  }
  renderPanel(toKey);
  if (window._allTeams) populateTeamSelect(toKey, window._allTeams);
  if (teams[toKey]) { const s = document.getElementById(`sel_${toKey}`); if (s) s.value = teams[toKey].id; }
  if (pair) {
    renderPanel(pair.toKey);
    if (window._allTeams) populateTeamSelect(pair.toKey, window._allTeams);
    if (teams[pair.toKey]) { const s = document.getElementById(`sel_${pair.toKey}`); if (s) s.value = teams[pair.toKey].id; }
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function pickLabel(p) { return `${p.season_year ?? '?'} · ${p.round == 1 ? '1ª Round' : '2ª Round'}`; }
function escH(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escA(s) { return String(s ?? '').replace(/"/g,'&quot;'); }

// ── Copy trade to clipboard ───────────────────────────────────────────────────
function copyTrade() {
  const lines = [];

  activeSlots.forEach(key => {
    const t = teams[key];
    if (!t) return;

    // Todos os itens que este time ENVIA (fromKey === key em qualquer receives)
    const sends = [];
    activeSlots.forEach(toKey => {
      if (toKey === key) return;
      (receives[toKey] || []).forEach(item => {
        if (item.fromKey === key) sends.push(item);
      });
    });

    if (!sends.length) return;

    lines.push(`${t.name.toUpperCase()} envia:`);
    sends.forEach(item => {
      if (item.type === 'player') {
        lines.push(`  • ${item.name} (${item.pos}, OVR ${item.ovr}/${item.age}a)`);
      } else {
        const swap = item.swapRole ? ` [${item.swapRole}]` : '';
        lines.push(`  • ${item.label} (${item.orig})${swap}`);
      }
    });
    lines.push('');
  });

  const text = lines.join('\n').trim();
  if (!text) { alert('Nenhum item na trade para copiar.'); return; }

  const btn = document.getElementById('copyTradeBtn');
  const restore = () => { if (btn) btn.innerHTML = '<i class="bi bi-clipboard"></i>Copiar'; };

  navigator.clipboard.writeText(text).then(() => {
    if (btn) btn.innerHTML = '<i class="bi bi-check2"></i>Copiado!';
    setTimeout(restore, 2000);
  }).catch(() => {
    // fallback para browsers sem clipboard API
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); if (btn) btn.innerHTML = '<i class="bi bi-check2"></i>Copiado!'; }
    catch(e) { alert(text); }
    document.body.removeChild(ta);
    setTimeout(restore, 2000);
  });
}

// ── Sidebar + Theme ───────────────────────────────────────────────────────────
(function(){
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('sbOverlay');
  const btn = document.getElementById('sidebarToggle');
  if (btn) btn.addEventListener('click', () => { sb.classList.add('open'); ov.classList.add('show'); });
  if (ov)  ov.addEventListener('click', () => { sb.classList.remove('open'); ov.classList.remove('show'); });
})();
(function(){
  const key = 'fba-theme';
  const btn = document.querySelector('[data-theme-toggle]');
  const apply = t => {
    document.documentElement.dataset.theme = t;
    localStorage.setItem(key, t);
    if (btn) btn.innerHTML = t === 'light' ? '<i class="bi bi-sun"></i><span>Modo claro</span>' : '<i class="bi bi-moon"></i><span>Modo escuro</span>';
  };
  apply(localStorage.getItem(key) || 'dark');
  if (btn) btn.addEventListener('click', () => apply(document.documentElement.dataset.theme === 'light' ? 'dark' : 'light'));
})();

boot();
</script>
</body>
</html>
