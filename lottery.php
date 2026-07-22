<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();
$user = getUserSession();
$pdo  = db();

$isGlobalAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
$adminLeagues  = getAdminLeagues($pdo, (int)$user['id']);
if (!$isGlobalAdmin && empty($adminLeagues)) {
    header('Location: /dashboard.php');
    exit;
}

$stmtMine = $pdo->prepare("SELECT id, city, name, league, photo_url FROM teams WHERE user_id = ? LIMIT 1");
$stmtMine->execute([$user['id']]);
$team = $stmtMine->fetch(PDO::FETCH_ASSOC) ?: null;

$stmtSessions = $pdo->prepare("
    SELECT ds.id, ds.status, s.season_number, s.year
    FROM draft_sessions ds
    JOIN seasons s ON s.id = ds.season_id
    WHERE ds.league = 'ELITE' AND ds.status = 'setup'
    ORDER BY s.season_number DESC
");
$stmtSessions->execute();
$setupSessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<meta name="theme-color" content="#fc0025">
<title>Loteria do Draft · FBA Manager</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:color-mix(in srgb, var(--red) 10%, transparent);--red-glow:color-mix(in srgb, var(--red) 30%, transparent);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;--border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--text:#f0f0f3;--text-2:#868690;--text-3:#7d7d85;--amber:#f59e0b;--green:#22c55e;--font:'Montserrat', sans-serif;--radius:14px;--radius-sm:10px;--sidebar-w:260px;--ease:cubic-bezier(.2,.8,.2,1);--t:200ms;--border-red:color-mix(in srgb, var(--red) 22%, transparent)}
:root[data-theme="light"]{--bg:#f6f7fb;--panel:#ffffff;--panel-2:#f2f4f8;--panel-3:#e9edf4;--border:#e3e6ee;--border-md:#d7dbe6;--text:#111217;--text-2:#5b6270;--text-3:#657080}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.icon-btn{width:34px;height:34px;border-radius:10px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:15px;cursor:pointer;text-decoration:none;transition:all .2s}
.icon-btn:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
.content{max-width:960px;margin:0 auto;padding:24px 16px 80px;width:100%}
.hero{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:24px}
.hero-title{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;display:flex;align-items:center;gap:10px}
.hero-title i{color:var(--red)}
.hero-sub{font-size:13px;color:var(--text-2);margin-top:6px;line-height:1.5}
.section-title{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin:22px 0 12px;display:flex;align-items:center;gap:8px}
.section-title i{color:var(--red)}
.info-hint{color:var(--text-3);font-size:12px;cursor:help;margin-left:4px}
.info-hint:hover{color:var(--red)}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px}
.form-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.form-field{flex:1;min-width:200px}
.form-field label{font-size:12px;color:var(--text-2);margin-bottom:6px;display:block}
.form-field select{width:100%;background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:9px 10px;color:var(--text);font-size:13px}
.btn-red{background:var(--red);border:none;border-radius:12px;padding:11px 20px;color:#fff;font-family:var(--font);font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:opacity .2s,transform .1s}
.btn-red:hover{opacity:.9}
.btn-red:active{transform:scale(.98)}
.btn-red:disabled{opacity:.45;cursor:not-allowed}
.btn-ghost2{background:transparent;border:1px solid var(--border);border-radius:12px;padding:11px 20px;color:var(--text-2);font-family:var(--font);font-weight:600;font-size:13px;cursor:pointer}
.btn-ghost2:hover{border-color:var(--border-red);color:var(--red)}
.empty{text-align:center;padding:24px;color:var(--text-3);font-size:13px}

/* Palco de revelação */
.reveal-stage{background:linear-gradient(160deg,var(--panel-2),var(--panel));border:1px solid var(--border-md);border-radius:18px;padding:30px 22px;text-align:center;position:relative;overflow:hidden}
.reveal-stage.armed{border-color:var(--border-red);box-shadow:0 0 0 1px var(--red-soft),0 20px 60px -20px var(--red-glow)}
/* camada de efeitos */
.reveal-fx{position:absolute;inset:0;pointer-events:none;z-index:0;overflow:hidden}
.reveal-stage > .reveal-pick,.reveal-stage > .reveal-card,.reveal-stage > .reveal-actions,.reveal-stage > .reveal-hint{position:relative;z-index:1}
/* burst radial ao assentar */
.reveal-stage.flash::after{content:'';position:absolute;left:50%;top:44%;width:16px;height:16px;border-radius:50%;transform:translate(-50%,-50%);pointer-events:none;z-index:0;animation:burstRing .75s ease-out forwards}
@keyframes burstRing{0%{box-shadow:0 0 0 0 var(--red-glow);opacity:.85}100%{box-shadow:0 0 0 380px transparent;opacity:0}}
/* subiu: brilho verde ambiente */
.reveal-stage.rise{animation:riseGlow 1.15s ease-out}
@keyframes riseGlow{0%{box-shadow:0 0 0 2px rgba(34,197,94,.55),0 0 70px -4px rgba(34,197,94,.65)}100%{box-shadow:0 0 0 1px var(--red-soft),0 20px 60px -20px var(--red-glow)}}
/* finale (#1): brilho dourado */
.reveal-stage.finale{animation:finaleGlow 1.4s ease-out}
@keyframes finaleGlow{0%{box-shadow:0 0 0 2px rgba(245,158,11,.6),0 0 90px 0 rgba(245,158,11,.55)}100%{box-shadow:0 0 0 1px var(--red-soft),0 20px 60px -20px var(--red-glow)}}
/* partículas subindo */
.fx-particle{position:absolute;bottom:34%;font-size:20px;font-weight:900;opacity:0;will-change:transform,opacity;animation:floatUp 1.15s ease-out forwards;z-index:0;text-shadow:0 0 8px currentColor}
@keyframes floatUp{0%{opacity:0;transform:translateY(10px) scale(.5)}15%{opacity:1}100%{opacity:0;transform:translateY(-160px) scale(1.25)}}
/* punch no número */
.reveal-number.punch{animation:numPunch .55s cubic-bezier(.2,1.3,.4,1)}
@keyframes numPunch{0%{transform:scale(.55);opacity:.2}55%{transform:scale(1.22)}100%{transform:scale(1)}}
.reveal-pick{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--red)}
.reveal-card{margin:16px auto 4px;min-height:200px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px}
.reveal-logo{width:120px;height:120px;border-radius:20px;object-fit:contain;background:var(--panel-3);border:1px solid var(--border-md);padding:8px;transition:opacity .3s,transform .3s}
.reveal-logo.pop{animation:logoPop .5s var(--ease)}
@keyframes logoPop{0%{transform:scale(.7);opacity:.3}60%{transform:scale(1.08)}100%{transform:scale(1)}}
.reveal-number{font-family:'Oswald',sans-serif;font-size:40px;font-weight:800;line-height:1;color:var(--text);opacity:.18}
.reveal-number.on{opacity:1;color:var(--red)}
.reveal-team{font-family:'Oswald',sans-serif;font-size:34px;font-weight:800;line-height:1.1;min-height:38px}
.reveal-team.shuffling{color:var(--text-2);filter:blur(.4px)}
.reveal-team.landed{animation:landPop .4s var(--ease)}
@keyframes landPop{0%{transform:scale(.82) translateY(6px);opacity:.35}55%{transform:scale(1.11) translateY(0)}78%{transform:scale(.97)}100%{transform:scale(1)}}
.reveal-move.up.show{animation:moveUpPop .6s cubic-bezier(.2,1.5,.4,1)}
@keyframes moveUpPop{0%{transform:scale(.5) translateY(18px);opacity:0}50%{transform:scale(1.22) translateY(-5px);opacity:1}100%{transform:scale(1) translateY(0)}}
.reveal-conf{font-size:12px;font-weight:700;letter-spacing:.5px;color:var(--text-3);text-transform:uppercase}
.reveal-move{margin-top:8px;display:inline-flex;align-items:center;gap:6px;font-size:14px;font-weight:800;padding:7px 18px;border-radius:999px;opacity:0;transition:opacity .3s}
.reveal-move.show{opacity:1}
.reveal-move.up{background:rgba(34,197,94,.14);color:var(--green);border:1px solid rgba(34,197,94,.35)}
.reveal-move.down{background:rgba(239,68,68,.14);color:#ef4444;border:1px solid rgba(239,68,68,.35)}
.reveal-move.same{background:var(--panel-3);color:var(--text-2);border:1px solid var(--border-md)}
.reveal-passed{margin-top:10px;display:none;flex-direction:column;align-items:center;gap:6px}
.reveal-passed.show{display:flex}
.reveal-passed-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-3)}
.reveal-passed-teams{display:flex;gap:8px;flex-wrap:wrap;justify-content:center}
.reveal-passed-team{display:inline-flex;align-items:center;gap:6px;background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.30);border-radius:999px;padding:4px 10px 4px 4px;font-size:12px;font-weight:700;color:var(--green)}
.reveal-passed-team img{width:20px;height:20px;border-radius:6px;object-fit:contain;background:var(--panel-3)}
.reveal-actions{margin-top:18px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.reveal-hint{font-size:11px;color:var(--text-3);margin-top:10px}

/* Urna — quem ainda está concorrendo */
.bowl-head{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
.bowl-count{font-family:'Oswald',sans-serif;font-size:13px;font-weight:800;color:var(--red)}
.bowl{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px}
.bowl-tile{display:flex;flex-direction:column;align-items:center;gap:6px;background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:12px 8px;text-align:center;transition:all .35s var(--ease)}
.bowl-tile.leaving{opacity:0;transform:scale(.6);filter:grayscale(1)}
.bowl-logo{width:52px;height:52px;border-radius:12px;object-fit:contain;background:var(--panel-3);border:1px solid var(--border-md);padding:4px}
.bowl-name{font-size:11px;font-weight:700;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.bowl-odds{font-family:'Oswald',sans-serif;font-size:15px;font-weight:800;color:var(--red)}
.bowl-pos{font-size:9px;color:var(--text-3);font-weight:700}
.bowl-empty{color:var(--text-3);font-size:13px;text-align:center;padding:10px}

/* Quadro da ordem */
.board{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
.board-slot{display:flex;align-items:center;gap:10px;background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:9px 12px;transition:all .3s var(--ease)}
.board-slot.pending{opacity:.5;border-style:dashed}
.board-slot.locked{opacity:.62}
.board-slot.just{border-color:var(--red);box-shadow:0 0 0 1px var(--red-soft);animation:slotIn .45s var(--ease)}
@keyframes slotIn{0%{transform:translateY(6px);opacity:.3}100%{transform:translateY(0);opacity:1}}
.board-pos{font-family:'Oswald',sans-serif;font-size:16px;font-weight:800;color:var(--red);width:26px;text-align:center;flex-shrink:0}
.board-slot.locked .board-pos{color:var(--text-3)}
.board-logo{width:26px;height:26px;border-radius:7px;object-fit:contain;background:var(--panel-3);border:1px solid var(--border-md);flex-shrink:0;padding:2px}
.board-team{flex:1;min-width:0;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:6px}
.board-team.q{color:var(--text-3);font-weight:400}
.board-tag{font-size:9px;font-weight:800;padding:2px 7px;border-radius:999px;flex-shrink:0;text-transform:uppercase;letter-spacing:.4px}
.board-tag.lottery{background:var(--red-soft);color:var(--red);border:1px solid var(--border-red)}
.board-tag.playoff{background:rgba(34,197,94,.10);color:var(--green);border:1px solid rgba(34,197,94,.28)}
.board-move{font-size:10px;font-weight:800;flex-shrink:0}
.board-move.up{color:var(--green)}.board-move.down{color:#ef4444}.board-move.same{color:var(--text-3)}

/* Chances */
.balls-table{width:100%;border-collapse:collapse;font-size:12px}
.balls-table th{padding:7px 8px;text-align:left;color:var(--text-3);font-weight:600;border-bottom:1px solid var(--border);font-size:10px;text-transform:uppercase}
.balls-table td{padding:7px 8px;border-bottom:1px solid var(--border)}
.balls-table tr:last-child td{border-bottom:none}
.balls-table td.num,.balls-table th.num{text-align:right;font-family:'Oswald',sans-serif;font-weight:700}
.conf-chip{font-size:9px;font-weight:800;padding:1px 6px;border-radius:999px;background:var(--panel-3);border:1px solid var(--border-md);color:var(--text-3);margin-left:6px}
.adjustments{display:flex;flex-direction:column;gap:8px}
.adjustment-item{display:flex;gap:8px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--text-2)}
.adjustment-item i{color:var(--amber);flex-shrink:0}
.rules-panel details{margin-bottom:8px}
.rules-panel summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--text);padding:6px 0}
.rules-panel summary:hover{color:var(--red)}
.rules-panel .rules-body{font-size:12px;color:var(--text-2);padding:6px 0 10px 4px;line-height:1.6}
@media(max-width:640px){
  .content{padding:18px 12px 72px}
  .panel{padding:16px 14px}
  .form-row{flex-direction:column;align-items:stretch}
  .board{grid-template-columns:1fr}
  .reveal-team{font-size:22px}
}

/* -- Layout com menu lateral -- */
.app { display: flex; min-height: 100vh; }
.sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; transition: transform var(--t) var(--ease); overflow-y: auto; scrollbar-width: none; }
.sidebar::-webkit-scrollbar { display: none; }
.sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
.sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
.sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
.sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
.sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
.sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
.sb-nav { flex: 1; padding: 12px 10px 8px; }
.sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
.sb-nav a { font-family:'Inter',sans-serif; display: flex; align-items: center; gap: 10px; padding: 10px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
.sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
.sb-nav a:hover { background: var(--panel-2); color: var(--text); }
.sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
.sb-nav a.active i { color: var(--red); }
.sb-theme-toggle{margin:10px 14px;display:flex;align-items:center;gap:8px;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:9px 12px;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;flex-shrink:0}
.sb-theme-toggle:hover{color:var(--text)}
.sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
.sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
.sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }
.topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 260; }
.topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
.topbar-title em { color: var(--red); font-style: normal; }
.menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
.sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
.sb-overlay.show { display: block; }
.main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
@media (max-width: 992px) {
  :root { --sidebar-w: 0px; }
  .sidebar { transform: translateX(-260px); }
  .sidebar.open { transform: translateX(0); }
  .main { margin-left: 0; width: 100%; padding-top: 54px; }
  .topbar { display: flex; }
  .content { padding-left: 16px; padding-right: 16px; }
}
/* selo de swap: pick que veio de outro time */
.via-badge{display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:800;letter-spacing:.5px;padding:2px 7px;border-radius:999px;background:rgba(168,85,247,.14);border:1px solid rgba(168,85,247,.38);color:#a855f7;text-transform:uppercase;white-space:nowrap;flex-shrink:0}
.reveal-via{margin-top:6px}
.reveal-via .via-badge{font-size:12px;padding:4px 12px}
body.broadcast .reveal-via .via-badge{font-size:14px;padding:5px 14px}
.podium-via{margin-top:6px}

/* ── Pódio do top-3 (aparece quando o sorteio termina) ── */
.podium{display:none;grid-template-columns:1fr 1.18fr 1fr;gap:14px;align-items:end;margin-bottom:16px}
body.bc-complete .podium{display:grid}
.podium-item{background:linear-gradient(180deg,var(--panel-2),var(--panel));border:1px solid var(--border-md);border-radius:16px;padding:18px 14px 20px;text-align:center;position:relative;overflow:hidden;animation:podiumIn .6s cubic-bezier(.2,1.2,.4,1) backwards}
.podium-item:nth-child(1){animation-delay:.05s}
.podium-item:nth-child(2){animation-delay:.2s}
.podium-item:nth-child(3){animation-delay:.35s}
@keyframes podiumIn{0%{opacity:0;transform:translateY(26px) scale(.94)}100%{opacity:1;transform:translateY(0) scale(1)}}
.podium-logo{width:92px;height:92px;border-radius:18px;object-fit:contain;background:var(--panel-3);border:1px solid var(--border-md);padding:6px;margin:0 auto 10px;display:block}
.podium-pos{font-family:'Oswald',sans-serif;font-size:34px;font-weight:800;line-height:1}
.podium-name{font-family:'Oswald',sans-serif;font-size:19px;font-weight:700;margin-top:6px;line-height:1.15}
.podium-conf{font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-top:3px}
.podium-move{margin-top:8px;display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:800;padding:3px 10px;border-radius:999px}
.podium-move.up{background:rgba(34,197,94,.14);color:var(--green);border:1px solid rgba(34,197,94,.35)}
.podium-move.down{background:rgba(239,68,68,.14);color:#ef4444;border:1px solid rgba(239,68,68,.35)}
.podium-move.same{background:var(--panel-3);color:var(--text-2);border:1px solid var(--border-md)}
/* medalhas */
.podium-item.gold{border-color:rgba(245,158,11,.55);box-shadow:0 0 0 1px rgba(245,158,11,.22),0 0 46px -12px rgba(245,158,11,.55);padding-top:28px;padding-bottom:30px}
.podium-item.gold .podium-pos{color:#f59e0b;font-size:42px}
.podium-item.gold .podium-logo{width:118px;height:118px}
.podium-item.gold .podium-name{font-size:23px}
.podium-item.silver{border-color:rgba(203,213,225,.42)}
.podium-item.silver .podium-pos{color:#cbd5e1}
.podium-item.bronze{border-color:rgba(217,119,6,.45)}
.podium-item.bronze .podium-pos{color:#d97706}
body.broadcast .podium{gap:20px;margin-bottom:20px}
body.broadcast .podium-logo{width:118px;height:118px}
body.broadcast .podium-item.gold .podium-logo{width:150px;height:150px}
body.broadcast .podium-name{font-size:24px}
body.broadcast .podium-item.gold .podium-name{font-size:30px}
body.broadcast .podium-pos{font-size:42px}
body.broadcast .podium-item.gold .podium-pos{font-size:54px}
@media(max-width:700px){.podium{grid-template-columns:1fr;align-items:stretch}}

/* Destaque do top-4 (vale sempre; brilha mais na transmissão) */
.board-slot.top4{position:relative;border-color:rgba(245,158,11,.45);background:linear-gradient(180deg,rgba(245,158,11,.10),transparent)}
.board-slot.top4 .board-pos{color:var(--amber)}
.board-slot.top4::after{content:'★';position:absolute;top:-7px;right:-6px;font-size:12px;color:var(--amber);text-shadow:0 0 6px rgba(245,158,11,.6)}

/* ── Modo transmissão (tela cheia p/ YouTube) ── */
body.broadcast{overflow-y:auto}
body.broadcast .sidebar,body.broadcast .topbar,body.broadcast .sb-overlay{display:none!important}
body.broadcast .main{margin-left:0!important;width:100%!important;padding-top:0!important}
body.broadcast .content{max-width:1680px;padding:16px 30px 30px!important}
body.broadcast .bc-off{display:none!important}
body.broadcast .info-hint{display:none!important}
body.broadcast .section-title{font-size:16px;letter-spacing:1px;margin:16px 0 10px}
/* layout: revelação (col 1) e urna (col 2) lado a lado; ordem full width embaixo */
body.broadcast #resultSection{display:grid!important;grid-template-columns:minmax(0,1.05fr) minmax(0,.95fr);gap:6px 20px;align-items:start}
body.broadcast .bc-reveal-title{display:none}
body.broadcast #revealStage{grid-column:1;grid-row:1 / span 2;align-self:stretch;display:flex;flex-direction:column;justify-content:center}
body.broadcast .bc-urna-title{grid-column:2;grid-row:1;margin:0 0 8px}
body.broadcast .bc-urna{grid-column:2;grid-row:2;align-self:start;max-height:52vh;overflow-y:auto}
body.broadcast .bc-board-title{grid-column:1 / -1;grid-row:3}
body.broadcast .bc-board{grid-column:1 / -1;grid-row:4}
body.broadcast #adjustmentsSection{grid-column:1 / -1;grid-row:5}
body.broadcast #confirmPanel{grid-column:1 / -1;grid-row:6}
/* sorteio completo: revelação e urna não são mais necessárias */
body.broadcast.bc-complete .bc-reveal-title,
body.broadcast.bc-complete #revealStage,
body.broadcast.bc-complete .bc-urna-title,
body.broadcast.bc-complete .bc-urna{display:none!important}
body.broadcast.bc-complete .bc-board-title{grid-row:1}
body.broadcast.bc-complete .bc-board{grid-row:2}
body.broadcast.bc-complete .board{grid-template-columns:repeat(4,1fr)}
/* reveal maior e imponente */
body.broadcast .reveal-stage{padding:36px 26px}
body.broadcast .reveal-card{min-height:230px}
body.broadcast .reveal-logo{width:150px;height:150px;border-radius:24px}
body.broadcast .reveal-number{font-size:52px}
body.broadcast .reveal-team{font-size:46px}
body.broadcast .reveal-pick{font-size:16px}
body.broadcast .reveal-move{font-size:16px;padding:8px 20px}
/* urna: logos maiores */
body.broadcast .bowl{grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:12px}
body.broadcast .bowl-logo{width:60px;height:60px}
body.broadcast .bowl-name{font-size:12px}
body.broadcast .bowl-odds{font-size:17px}
/* ordem em 4 colunas pra caber na tela */
body.broadcast .board{grid-template-columns:repeat(4,1fr);gap:9px}
body.broadcast .board-slot.top4::after{font-size:15px;top:-9px}
.btn-broadcast-exit{display:none}
body.broadcast .btn-broadcast-exit{display:inline-flex;position:fixed;top:14px;right:16px;z-index:9999;align-items:center;gap:7px;background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2);border-radius:10px;padding:8px 14px;font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer}
.btn-broadcast-exit:hover{border-color:var(--border-red);color:var(--red)}
@media(max-width:820px){
  body.broadcast .board{grid-template-columns:repeat(2,1fr)}
  body.broadcast .reveal-team{font-size:32px}
  body.broadcast .reveal-logo{width:110px;height:110px}
}
<?php include __DIR__ . '/includes/accent-color.php'; ?>
</style>
</head>
<body>
<div class="app">

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">Loteria do <em>Draft</em></div>
  <a href="admin.php" class="icon-btn"><i class="bi bi-arrow-left"></i></a>
</header>

<button class="btn-broadcast-exit" onclick="toggleBroadcast()"><i class="bi bi-fullscreen-exit"></i> Sair da transmissão</button>
<main class="main">
 <div class="content">

  <div class="hero bc-off">
    <div class="hero-title"><i class="bi bi-shuffle"></i> Loteria do Draft — ELITE</div>
    <div class="hero-sub">A ordem é sorteada de uma vez no servidor (justa, com base nas chances), e você revela pick por
    pick no clique — igual à cerimônia da NBA. Times fora do playoff das duas conferências entram no sorteio; os 16 do
    playoff (8 de cada conferência) já ficam travados no fim da ordem.</div>
  </div>

  <?php if (!$setupSessions): ?>
  <div class="panel empty">
    <i class="bi bi-info-circle" style="font-size:22px;color:var(--text-3)"></i>
    <p style="margin-top:10px">Nenhuma sessão de draft ELITE com status "setup" encontrada. Crie a sessão de draft
    da próxima temporada primeiro (na tela de Draft) antes de sortear a ordem aqui.</p>
  </div>
  <?php else: ?>

  <div class="section-title bc-off"><i class="bi bi-calendar2-check"></i> 1. Escolha a sessão de draft</div>
  <div class="panel bc-off">
    <div class="form-row">
      <div class="form-field">
        <label>Sessão de draft (ELITE)</label>
        <select id="sessionSelect">
          <?php foreach ($setupSessions as $s): ?>
          <option value="<?= (int)$s['id'] ?>">Temporada <?= (int)$s['season_number'] ?><?= $s['year'] ? ' (' . htmlspecialchars($s['year']) . ')' : '' ?> — sessão #<?= (int)$s['id'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn-red" id="btnPrepare"><i class="bi bi-dice-5-fill"></i> Preparar Loteria</button>
    </div>
  </div>

  <div id="resultSection" style="display:none">

    <div class="section-title bc-off"><i class="bi bi-percent"></i> Chances da loteria<i class="bi bi-question-circle info-hint" title="Só os times fora do playoff das duas conferências entram. Chance % = bolinhas do time ÷ total de bolinhas. Isto é mostrado ANTES de revelar, pra todos saberem as probabilidades."></i></div>
    <div class="panel bc-off">
      <div style="overflow-x:auto">
        <table class="balls-table" id="ballsTable">
          <thead><tr><th>Time</th><th class="num">Posição</th><th class="num">Bolinhas</th><th class="num">Chance da #1</th></tr></thead>
          <tbody id="ballsBody"></tbody>
        </table>
      </div>
    </div>

    <div class="section-title bc-reveal-title"><i class="bi bi-stars"></i> 2. Revelação<i class="bi bi-question-circle info-hint" title="Clique em 'Revelar próxima' para revelar uma pick de cada vez, da última até a #1. O badge mostra se o time subiu ou caiu em relação à posição que teria só pela campanha."></i></div>
    <div class="reveal-stage" id="revealStage">
      <div class="reveal-fx" id="revealFx" aria-hidden="true"></div>
      <div class="reveal-pick" id="revealPickLabel">Pronto para começar</div>
      <div class="reveal-card">
        <img class="reveal-logo" id="revealLogo" src="/img/default-team.png" alt="" style="visibility:hidden" onerror="this.src='/img/default-team.png'">
        <div class="reveal-number" id="revealNumber">#?</div>
        <div class="reveal-team q" id="revealTeam">—</div>
        <div class="reveal-conf" id="revealConf"></div>
        <div class="reveal-via" id="revealVia"></div>
        <div class="reveal-move" id="revealMove"></div>
        <div class="reveal-passed" id="revealPassed"></div>
      </div>
      <div class="reveal-actions">
        <button class="btn-red" id="btnReveal"><i class="bi bi-caret-right-fill"></i> Revelar próxima escolha</button>
        <button class="btn-ghost2" id="btnBroadcast" onclick="toggleBroadcast()"><i class="bi bi-fullscreen"></i> Modo transmissão</button>
      </div>
      <div class="reveal-hint" id="revealHint">A revelação começa pela última pick e sobe até a #1.</div>
    </div>

    <div class="section-title bc-urna-title"><i class="bi bi-collection-fill"></i> Ainda na urna <span class="bowl-count" id="bowlCount"></span><i class="bi bi-question-circle info-hint" title="Times que ainda não foram revelados — qualquer um deles ainda pode pegar as melhores picks. A urna esvazia a cada revelação."></i></div>
    <div class="panel bc-urna">
      <div class="bowl" id="bowl"></div>
    </div>

    <div class="section-title bc-podium-title" id="podiumTitle" style="display:none"><i class="bi bi-trophy-fill"></i> Pódio da loteria</div>
    <div class="podium" id="podium"></div>

    <div class="section-title bc-board-title"><i class="bi bi-list-ol"></i> Ordem do draft</div>
    <div class="panel bc-board">
      <div class="board" id="board"></div>
    </div>

    <div id="adjustmentsSection" style="display:none">
      <div class="section-title"><i class="bi bi-shield-exclamation"></i> Ajustes anti-tanking aplicados</div>
      <div class="panel"><div class="adjustments" id="adjustmentsList"></div></div>
    </div>

    <div class="panel" id="confirmPanel" style="display:none">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button class="btn-red" id="btnConfirm"><i class="bi bi-check-lg"></i> Confirmar e aplicar ao draft</button>
        <button class="btn-ghost2" id="btnRedo"><i class="bi bi-arrow-repeat"></i> Sortear de novo</button>
        <span style="font-size:11px;color:var(--text-3)">Aplica esta ordem nas duas rodadas do draft (a 2ª reaproveita a mesma ordem).</span>
      </div>
    </div>
  </div>

  <div class="section-title bc-off"><i class="bi bi-journal-text"></i> Como funciona</div>
  <div class="panel rules-panel bc-off">
    <details>
      <summary><i class="bi bi-diagram-2"></i> Quem entra na loteria</summary>
      <div class="rules-body">Playoff = os 8 melhores de cada conferência (16 no total, contando as duas). Todos os outros
      times, das duas conferências, entram na loteria pelas primeiras picks. Os 16 do playoff pegam as últimas picks,
      em ordem inversa do quão longe foram (o campeão escolhe por último).</div>
    </details>
    <details>
      <summary><i class="bi bi-circle-half"></i> Bolinhas e chances</summary>
      <div class="rules-body">Entre os times de loteria: os ~30% piores ganham 2 bolinhas (faixa "relegada" — o pior
      time NÃO tem a maior chance, é anti-tanking), a faixa do meio ganha 3 (pico de chance), e os mais perto do corte
      de playoff ganham 2 ou 1. As picks 1 a 4 são sorteadas por peso; o resto vem em ordem inversa de campanha.</div>
    </details>
    <details>
      <summary><i class="bi bi-shield-exclamation"></i> Anti-tanking</summary>
      <div class="rules-body">Um time não pode ganhar a pick #1 duas temporadas seguidas, nem ficar 3 temporadas
      seguidas com pick entre 1 e 5. Se o sorteio esbarrar nisso, o ajuste é aplicado e aparece listado.</div>
    </details>
  </div>

  <?php endif; ?>
 </div>
</main>
</div>

<script>
(function(){
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sbOverlay');
  const menuBtn = document.getElementById('menuBtn');
  if (menuBtn) menuBtn.addEventListener('click', () => { sidebar?.classList.add('open'); overlay?.classList.add('show'); });
  if (overlay) overlay.addEventListener('click', () => { sidebar?.classList.remove('open'); overlay.classList.remove('show'); });

  const themeToggle = document.getElementById('themeToggle');
  const themeKey = 'fba-theme';
  const applyTheme = (theme) => {
    if (theme === 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
      if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
      return;
    }
    document.documentElement.removeAttribute('data-theme');
    if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
  };
  applyTheme(localStorage.getItem(themeKey) || 'dark');
  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
      const next = current === 'light' ? 'dark' : 'light';
      localStorage.setItem(themeKey, next);
      applyTheme(next);
    });
  }
})();

<?php if ($setupSessions): ?>
function esc(s){ if(!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* selo "via XXX" quando a pick veio de outro time (swap/troca) */
function viaTag(o){
  return (o && o.is_swap && o.origin_abbr)
    ? `<span class="via-badge" title="Pick originalmente do ${esc(o.origin_name || '')}">via ${esc(o.origin_abbr)}</span>`
    : '';
}

/* pódio do top-3 (montado quando o sorteio termina) */
function renderPodium(){
  if (!result) return;
  const byPos = p => result.order.find(o => o.position === p);
  const p1 = byPos(1), p2 = byPos(2), p3 = byPos(3);
  if (!p1) return;
  const item = (o, cls) => {
    if (!o) return '';
    const d = o.delta || 0;
    const mv = d > 0 ? `<span class="podium-move up"><i class="bi bi-arrow-up-short"></i> Subiu ${d}</span>`
             : d < 0 ? `<span class="podium-move down"><i class="bi bi-arrow-down-short"></i> Caiu ${Math.abs(d)}</span>`
             : `<span class="podium-move same"><i class="bi bi-dash"></i> Manteve</span>`;
    const src = o.photo_url || LOGO_FALLBACK;
    return `<div class="podium-item ${cls}">
      <img class="podium-logo" src="${esc(src)}" alt="" loading="eager" onerror="this.src='${LOGO_FALLBACK}'">
      <div class="podium-pos">#${o.position}</div>
      <div class="podium-name">${esc(o.team_name)}</div>
      <div class="podium-conf">${esc(o.conference || '')}</div>
      ${o.is_swap ? `<div class="podium-via">${viaTag(o)}</div>` : ''}
      ${mv}
    </div>`;
  };
  // pódio clássico: 2º à esquerda, 1º ao centro (maior), 3º à direita
  $('podium').innerHTML = item(p2, 'silver') + item(p1, 'gold') + item(p3, 'bronze');
  $('podiumTitle').style.display = 'flex';
}

/* partículas que sobem no palco de revelação */
function spawnParticles(symbol, color, count){
  const fx = document.getElementById('revealFx');
  if (!fx) return;
  for (let i = 0; i < count; i++){
    const p = document.createElement('span');
    p.className = 'fx-particle';
    p.textContent = symbol;
    p.style.color = color;
    p.style.left = (6 + Math.random() * 88) + '%';
    p.style.bottom = (26 + Math.random() * 18) + '%';
    p.style.fontSize = (14 + Math.random() * 16) + 'px';
    p.style.animationDelay = (Math.random() * 0.3) + 's';
    fx.appendChild(p);
    setTimeout(() => p.remove(), 1600);
  }
}

/* ── Modo transmissão (tela cheia) ── */
function toggleBroadcast(){
  const on = document.body.classList.toggle('broadcast');
  const btn = document.getElementById('btnBroadcast');
  if (on) {
    const el = document.documentElement;
    if (el.requestFullscreen) el.requestFullscreen().catch(()=>{});
    if (btn) btn.innerHTML = '<i class="bi bi-fullscreen-exit"></i> Sair da transmissão';
  } else {
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(()=>{});
    if (btn) btn.innerHTML = '<i class="bi bi-fullscreen"></i> Modo transmissão';
  }
}
document.addEventListener('fullscreenchange', () => {
  if (!document.fullscreenElement && document.body.classList.contains('broadcast')) {
    document.body.classList.remove('broadcast');
    const btn = document.getElementById('btnBroadcast');
    if (btn) btn.innerHTML = '<i class="bi bi-fullscreen"></i> Modo transmissão';
  }
});

let result = null;       // resposta do run_lottery
let revealQueue = [];    // posições de loteria a revelar (da última pra #1)
let revealed = new Set();
let busy = false;

const $ = (id) => document.getElementById(id);

async function prepare(){
  const sessionId = $('sessionSelect').value;
  const btn = $('btnPrepare');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Preparando...';
  try {
    const res = await fetch('/api/draft.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'run_lottery', draft_session_id: parseInt(sessionId, 10) })
    });
    const data = await res.json();
    if (!data.success) { alert(data.error || 'Erro ao preparar a loteria.'); return; }
    result = data;
    setupBoardAndOdds(data);
    $('resultSection').style.display = 'block';
  } catch (e) {
    alert('Erro ao preparar a loteria.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-dice-5-fill"></i> Preparar Loteria';
  }
}

const LOGO_FALLBACK = '/img/default-team.png';
let photoById = {};
function logo(url, cls){
  const src = url || LOGO_FALLBACK;
  return `<img class="${cls}" src="${esc(src)}" alt="" loading="lazy" onerror="this.src='${LOGO_FALLBACK}'">`;
}

function setupBoardAndOdds(data){
  revealed = new Set();
  busy = false;
  photoById = {};
  data.order.forEach(o => { photoById[o.team_id] = o.photo_url; });

  // Chances (antes de revelar)
  $('ballsBody').innerHTML = data.balls.map(b => `
    <tr>
      <td><span style="display:inline-flex;align-items:center;gap:8px">${logo(b.photo_url,'board-logo')}${esc(b.team_name)}${b.conference ? `<span class="conf-chip">${esc(b.conference)}</span>` : ''}</span></td>
      <td class="num">${b.position_anterior}º</td>
      <td class="num">${b.balls}</td>
      <td class="num">${b.odds_pct}%</td>
    </tr>
  `).join('');

  // Ajustes anti-tanking
  const adjSection = $('adjustmentsSection');
  if (data.adjustments && data.adjustments.length) {
    adjSection.style.display = 'block';
    $('adjustmentsList').innerHTML = data.adjustments.map(a => `
      <div class="adjustment-item"><i class="bi bi-exclamation-triangle-fill"></i> ${esc(a)}</div>
    `).join('');
  } else {
    adjSection.style.display = 'none';
  }

  // Urna: times de loteria ainda concorrendo (esvazia a cada revelação)
  const lotteryTeams = data.balls.slice(); // já vem do pior pro "menos pior"
  $('bowl').innerHTML = lotteryTeams.map(b => `
    <div class="bowl-tile" id="bowl-${b.team_id}">
      ${logo(b.photo_url,'bowl-logo')}
      <div class="bowl-name">${esc(b.team_name)}</div>
      <div class="bowl-odds">${b.odds_pct}%</div>
      <div class="bowl-pos">${b.position_anterior}º ${esc(b.conference || '')}</div>
    </div>
  `).join('');
  updateBowlCount(lotteryTeams.length);

  // Quadro: loteria pendente (?) no topo, playoff já travado embaixo
  $('board').innerHTML = data.order.map(o => {
    const isPlayoff = o.source === 'playoff';
    const top4 = o.position <= 4 ? ' top4' : '';
    if (isPlayoff) {
      return `<div class="board-slot locked${top4}" id="board-slot-${o.position}">
        <span class="board-pos">${o.position}</span>
        ${logo(o.photo_url,'board-logo')}
        <span class="board-team">${esc(o.team_name)}${o.is_swap ? ' ' + viaTag(o) : ''}</span>
        <span class="board-tag playoff"><i class="bi bi-lock-fill"></i> Playoff</span>
      </div>`;
    }
    return `<div class="board-slot pending${top4}" id="board-slot-${o.position}">
      <span class="board-pos">${o.position}</span>
      <img class="board-logo" id="board-logo-${o.position}" src="${LOGO_FALLBACK}" alt="" style="visibility:hidden" onerror="this.src='${LOGO_FALLBACK}'">
      <span class="board-team q" id="board-team-${o.position}">Aguardando...</span>
      <span class="board-tag lottery" style="visibility:hidden" id="board-tag-${o.position}">Loteria</span>
    </div>`;
  }).join('');

  // Fila de revelação: só picks de loteria, da última (maior posição) até a #1
  revealQueue = data.order
    .filter(o => o.source !== 'playoff')
    .sort((a, b) => b.position - a.position)
    .map(o => o.position);

  // Estado do palco
  $('confirmPanel').style.display = 'none';
  $('revealStage').classList.remove('armed');
  $('revealLogo').style.visibility = 'hidden';
  $('revealLogo').className = 'reveal-logo';
  $('revealNumber').className = 'reveal-number';
  $('revealNumber').textContent = '#?';
  $('revealTeam').className = 'reveal-team q';
  $('revealTeam').textContent = '—';
  $('revealConf').textContent = '';
  $('revealMove').className = 'reveal-move';
  $('revealMove').textContent = '';
  $('revealPassed').className = 'reveal-passed';
  $('revealPassed').innerHTML = '';
  $('revealVia').innerHTML = '';
  $('podium').innerHTML = '';
  $('podiumTitle').style.display = 'none';
  updateRevealButton();
}

function updateBowlCount(n){
  $('bowlCount').textContent = n > 0 ? `${n} ${n === 1 ? 'time concorrendo' : 'times concorrendo'}` : 'urna vazia';
}

function updateRevealButton(){
  const btn = $('btnReveal');
  if (!revealQueue.length) {
    btn.style.display = 'none';
    $('revealPickLabel').textContent = 'Sorteio completo';
    $('revealHint').textContent = 'Todas as picks de loteria reveladas. Confira a ordem e confirme abaixo.';
    $('confirmPanel').style.display = 'block';
    $('revealStage').classList.remove('armed');
    document.body.classList.add('bc-complete'); // esconde revelação/urna na transmissão
    renderPodium();                             // mostra o pódio do top-3
    return;
  }
  document.body.classList.remove('bc-complete');
  const nextPos = revealQueue[0];
  btn.style.display = 'inline-flex';
  btn.disabled = false;
  btn.innerHTML = `<i class="bi bi-caret-right-fill"></i> Revelar pick #${nextPos}`;
  $('revealPickLabel').textContent = revealQueue.length === 1 ? 'A escolha nº 1 — grande final' : `Faltam ${revealQueue.length} escolhas`;
}

function revealNext(){
  if (busy || !revealQueue.length) return;
  busy = true;
  const pos = revealQueue.shift();
  const entry = result.order.find(o => o.position === pos);
  const btn = $('btnReveal');
  btn.disabled = true;
  $('revealStage').classList.add('armed');

  // decoys = times de loteria ainda não revelados (pra embaralhar nome + logo)
  const decoys = result.order
    .filter(o => o.source !== 'playoff' && !revealed.has(o.position) && o.position !== pos);
  const pool = decoys.length ? decoys : [entry];

  const numEl = $('revealNumber'), teamEl = $('revealTeam'), confEl = $('revealConf'), moveEl = $('revealMove'), logoEl = $('revealLogo'), passedEl = $('revealPassed');
  numEl.className = 'reveal-number on';
  numEl.textContent = '#' + pos;
  confEl.textContent = '';
  moveEl.className = 'reveal-move';
  moveEl.textContent = '';
  passedEl.className = 'reveal-passed';
  passedEl.innerHTML = '';
  $('revealVia').innerHTML = '';
  teamEl.className = 'reveal-team shuffling';
  logoEl.className = 'reveal-logo';
  logoEl.style.visibility = 'visible';

  let steps = 12 + Math.floor(Math.random() * 4);
  let delay = 55;
  function tick(){
    if (steps <= 0) { land(); return; }
    const dc = pool[Math.floor(Math.random() * pool.length)];
    teamEl.textContent = dc.team_name;
    logoEl.src = dc.photo_url || LOGO_FALLBACK;
    steps--; delay += 14;
    setTimeout(tick, delay);
  }
  function land(){
    teamEl.className = 'reveal-team landed';
    teamEl.textContent = entry.team_name;
    confEl.textContent = entry.conference || '';
    logoEl.src = entry.photo_url || LOGO_FALLBACK;
    logoEl.className = 'reveal-logo pop';
    $('revealVia').innerHTML = viaTag(entry);

    // ── Efeitos da revelação ──
    const stage = $('revealStage');
    const isFinale = pos === 1;
    stage.classList.remove('flash','rise','finale');
    void stage.offsetWidth; // reinicia animações
    stage.classList.add(isFinale ? 'finale' : 'flash');
    setTimeout(() => stage.classList.remove('flash','finale'), 1500);
    numEl.classList.remove('punch'); void numEl.offsetWidth; numEl.classList.add('punch');

    // movimento
    const d = entry.delta || 0;
    if (d > 0) { moveEl.className = 'reveal-move up show'; moveEl.innerHTML = `<i class="bi bi-arrow-up-short"></i> Subiu ${d} ${d===1?'posição':'posições'}`; }
    else if (d < 0) { moveEl.className = 'reveal-move down show'; moveEl.innerHTML = `<i class="bi bi-arrow-down-short"></i> Caiu ${Math.abs(d)} ${Math.abs(d)===1?'posição':'posições'}`; }
    else { moveEl.className = 'reveal-move same show'; moveEl.innerHTML = `<i class="bi bi-dash"></i> Manteve a posição`; }

    // Subiu: brilho verde + partículas ▲ (mais intensas quanto maior o salto)
    if (d > 0) {
      stage.classList.add('rise');
      setTimeout(() => stage.classList.remove('rise'), 1200);
      spawnParticles('▲', '#22c55e', Math.min(4 + d, 14));
    }
    // Finale (#1): chuva dourada de estrelas
    if (isFinale) spawnParticles('★', '#f59e0b', 16);

    // Quem passou na frente (só quando caiu)
    const passed = entry.passed_by || [];
    if (d < 0 && passed.length) {
      passedEl.innerHTML = `<div class="reveal-passed-label"><i class="bi bi-arrow-up"></i> Ultrapassado por</div>
        <div class="reveal-passed-teams">${passed.map(t => `<span class="reveal-passed-team">${logo(t.photo_url,'')}${esc(t.team_name)}</span>`).join('')}</div>`;
      passedEl.className = 'reveal-passed show';
    }

    // tira o time da urna (esvazia)
    const tile = $('bowl-' + entry.team_id);
    if (tile) { tile.classList.add('leaving'); setTimeout(() => tile.remove(), 360); }
    updateBowlCount(revealQueue.length); // quantos ainda faltam revelar = ainda na urna

    // preenche o quadro
    const slot = $('board-slot-' + pos);
    const teamSlot = $('board-team-' + pos);
    const tagSlot = $('board-tag-' + pos);
    const logoSlot = $('board-logo-' + pos);
    if (slot) { slot.classList.remove('pending'); slot.classList.add('just'); setTimeout(()=>slot.classList.remove('just'), 700); }
    if (logoSlot) { logoSlot.src = entry.photo_url || LOGO_FALLBACK; logoSlot.style.visibility = 'visible'; }
    if (teamSlot) {
      teamSlot.classList.remove('q');
      const badge = d > 0 ? ` <span class="board-move up">▲${d}</span>` : (d < 0 ? ` <span class="board-move down">▼${Math.abs(d)}</span>` : '');
      teamSlot.innerHTML = esc(entry.team_name) + (entry.is_swap ? ' ' + viaTag(entry) : '') + badge;
    }
    if (tagSlot) tagSlot.style.visibility = 'visible';

    revealed.add(pos);
    busy = false;
    updateRevealButton();
  }
  tick();
}

async function confirmOrder(){
  if (!result) return;
  if (!confirm('Confirmar essa ordem e aplicar ao draft? Isso substitui qualquer ordem já definida para as duas rodadas dessa sessão.')) return;
  const btn = $('btnConfirm');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Aplicando...';
  try {
    const teamOrder = result.order.map(o => o.team_id);
    const res = await fetch('/api/draft.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'set_draft_order', draft_session_id: result.draft_session_id, team_order: teamOrder })
    });
    const data = await res.json();
    if (!data.success) { alert(data.error || 'Erro ao aplicar a ordem.'); return; }
    alert('Ordem aplicada com sucesso ao draft!');
  } catch (e) {
    alert('Erro ao aplicar a ordem.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check-lg"></i> Confirmar e aplicar ao draft';
  }
}

$('btnPrepare').addEventListener('click', prepare);
$('btnReveal').addEventListener('click', revealNext);
$('btnConfirm').addEventListener('click', confirmOrder);
$('btnRedo').addEventListener('click', prepare);
<?php endif; ?>
</script>
</body>
</html>
