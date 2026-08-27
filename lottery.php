<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();
$user = getUserSession();
$pdo  = db();

$isGlobalAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
$adminLeagues  = getAdminLeagues($pdo, (int)$user['id']);
// Qualquer jogador pode ver a loteria (regras + ordem já sorteada); só quem
// administra ELITE/NEXT/RISE/ROOKIE consegue de fato rodar a cerimônia e confirmar.
$canRunLottery = $isGlobalAdmin || !empty($adminLeagues);

$stmtMine = $pdo->prepare("SELECT id, city, name, league, photo_url FROM teams WHERE user_id = ? LIMIT 1");
$stmtMine->execute([$user['id']]);
$team = $stmtMine->fetch(PDO::FETCH_ASSOC) ?: null;

// A loteria só mostra as ligas que o GM logado administra (as demais, não).
$lotteryLeagues = array_values(array_intersect(['ELITE', 'NEXT', 'RISE', 'ROOKIE'], $adminLeagues));
$setupSessions = [];
if ($lotteryLeagues) {
    $ph = implode(',', array_fill(0, count($lotteryLeagues), '?'));
    $stmtSessions = $pdo->prepare("
        SELECT ds.id, ds.status, ds.league, s.season_number, s.year
        FROM draft_sessions ds
        JOIN seasons s ON s.id = ds.season_id
        WHERE ds.league IN ($ph) AND ds.status = 'setup'
        ORDER BY FIELD(ds.league,'ELITE','NEXT','RISE','ROOKIE'), s.season_number DESC
    ");
    $stmtSessions->execute($lotteryLeagues);
    $setupSessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);
}

// Ordem já confirmada (via "Confirmar e aplicar ao draft") da liga do jogador
// logado — visível pra todo mundo, mesmo quem não administra a loteria.
$myViewLeague = strtoupper((string)($team['league'] ?? $user['league'] ?? ''));
$confirmedOrder = [];
$confirmedSessionInfo = null;
if (in_array($myViewLeague, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
    $stmtLastSession = $pdo->prepare("
        SELECT ds.id, s.season_number, s.year
        FROM draft_sessions ds
        JOIN seasons s ON s.id = ds.season_id
        WHERE ds.league = ?
        ORDER BY ds.id DESC
        LIMIT 1
    ");
    $stmtLastSession->execute([$myViewLeague]);
    $lastSession = $stmtLastSession->fetch(PDO::FETCH_ASSOC);
    if ($lastSession) {
        $stmtOrder = $pdo->prepare("
            SELECT do.pick_position, CONCAT(t.city,' ',t.name) AS team_name, t.photo_url, t.conference
            FROM draft_order do
            JOIN teams t ON t.id = do.team_id
            WHERE do.draft_session_id = ? AND do.round = 1
            ORDER BY do.pick_position ASC
        ");
        $stmtOrder->execute([(int)$lastSession['id']]);
        $confirmedOrder = $stmtOrder->fetchAll(PDO::FETCH_ASSOC);
        if ($confirmedOrder) {
            $confirmedSessionInfo = $lastSession;
        }
    }
}
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
.content{padding:20px 32px 40px;width:100%}
/* Cabeçalho padrão do site (mesmo de trades.php) — plano, sem card.
   Fica dentro de .content, então já herda o max-width/padding lateral da coluna. */
.page-hero{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:24px}
.page-hero-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.page-hero-title{font-size:26px;font-weight:800;color:var(--text);line-height:1.1}
.page-hero-sub{font-size:13px;color:var(--text-2);margin-top:4px;line-height:1.5}
.page-hero-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
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

/* Globo de bolinhas — sorteio de cada pick */
.ball-machine{position:relative;width:min(210px,62vw);height:min(210px,62vw);margin:0 auto;display:none}
.ball-machine.on{display:block}
.ball-globe{position:absolute;inset:0;border-radius:50%;border:3px solid var(--border-md);overflow:hidden;
  background:radial-gradient(circle at 32% 26%, rgba(255,255,255,.10), transparent 58%), var(--panel-3);
  box-shadow:inset 0 0 42px rgba(0,0,0,.55), 0 0 0 1px var(--red-soft)}
.ball-globe::after{content:'';position:absolute;left:14%;top:10%;width:34%;height:22%;border-radius:50%;
  background:rgba(255,255,255,.09);filter:blur(6px);pointer-events:none}
.lottery-ball{position:absolute;left:50%;top:50%;width:42px;height:42px;margin:-21px 0 0 -21px;border-radius:50%;
  background:var(--panel);border:2px solid var(--border-md);display:flex;align-items:center;justify-content:center;
  overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,.45);
  animation-name:ballTumble;animation-timing-function:linear;animation-iteration-count:infinite;
  transition:opacity .45s var(--ease)}
.lottery-ball img{width:30px;height:30px;object-fit:contain;pointer-events:none}
@keyframes ballTumble{
  from{transform:rotate(0deg) translateX(var(--r)) rotate(0deg)}
  to{transform:rotate(360deg) translateX(var(--r)) rotate(-360deg)}
}
.ball-globe.slowing .lottery-ball{opacity:.22;animation-duration:3.6s!important}
@keyframes ballDrawn{
  0%{transform:scale(.65);opacity:.35}
  55%{transform:scale(2.15)}
  100%{transform:scale(1.95);opacity:1}
}
.lottery-ball.drawn{z-index:5;opacity:1!important;border-color:var(--red);
  box-shadow:0 0 0 4px var(--red-soft),0 0 34px var(--red-glow);
  animation:ballDrawn .78s cubic-bezier(.2,1.25,.4,1) forwards!important}
body.broadcast .ball-machine{width:min(280px,52vw);height:min(280px,52vw)}
body.broadcast .lottery-ball{width:52px;height:52px;margin:-26px 0 0 -26px}
body.broadcast .lottery-ball img{width:38px;height:38px}
@media(prefers-reduced-motion:reduce){
  .lottery-ball{animation:none!important}
  .lottery-ball.drawn{animation:none!important;transform:scale(1.9)}
}

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
/* Slot editável (só na prévia): opaco, porque aqui o time já é conhecido —
   o "aguardando" tracejado é pra depois do sorteio, antes de revelar. */
.board-slot.editavel{opacity:1;border-style:solid}
.board-slot.editavel .board-team{color:var(--text-1)}
.board-move{display:flex;flex-direction:column;gap:2px;margin-left:auto}
.board-move button{width:22px;height:16px;display:flex;align-items:center;justify-content:center;background:var(--panel);border:1px solid var(--border);border-radius:4px;color:var(--text-3);cursor:pointer;font-size:9px;padding:0;line-height:1}
.board-move button:hover:not(:disabled){color:var(--text-1);border-color:var(--red)}
.board-move button:disabled{opacity:.25;cursor:default}
.board-slot.movido{border-color:var(--red);box-shadow:0 0 0 1px var(--red-soft)}
.ordem-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
  margin-bottom:10px;padding:9px 12px;border-radius:10px;font-size:12px;
  background:var(--red-soft);border:1px solid var(--border-red);color:var(--text-1)}
.ordem-bar-acoes{display:flex;gap:8px;flex-shrink:0}
@media(max-width:640px){
  .ordem-bar{font-size:11px}.ordem-bar-acoes{width:100%}.ordem-bar-acoes button{flex:1}
  /* No dedo, 16px de altura é alvo de errar: as setas crescem. */
  .board-move button{width:34px;height:24px;font-size:11px}
}
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
/* Acordos resolvidos pela ordem: proteção e swap. Verde quando a pick passou
   pro credor, âmbar quando a proteção segurou, azul pro swap — o admin lê a
   lista de relance e o que importa é distinguir "mudou de dono" de "ficou". */
.ev{display:flex;gap:10px;align-items:flex-start;padding:9px 11px;border-radius:8px;
  border:1px solid var(--border);background:var(--panel-3);margin-bottom:7px}
.ev-tag{flex-shrink:0;font-size:9px;font-weight:800;letter-spacing:.04em;padding:2px 7px;
  border-radius:999px;background:rgba(34,197,94,.15);color:#22c55e;border:1px solid rgba(34,197,94,.35)}
.ev.barrado .ev-tag{background:rgba(245,158,11,.15);color:#f59e0b;border-color:rgba(245,158,11,.35)}
.ev.swap .ev-tag{background:rgba(96,165,250,.15);color:#60a5fa;border-color:rgba(96,165,250,.35)}
.ev-txt{font-size:12.5px;line-height:1.45;color:var(--text)}
.ev-extra{font-size:11.5px;color:var(--text-2);margin-top:3px}
.adjustment-item{display:flex;gap:8px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--text-2)}
.adjustment-item i{color:var(--amber);flex-shrink:0}
.rules-panel details{margin-bottom:8px}
.rules-panel summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--text);padding:6px 0}
.rules-panel summary:hover{color:var(--red)}
.rules-panel .rules-body{font-size:12px;color:var(--text-2);padding:6px 0 10px 4px;line-height:1.6}
@media(max-width:640px){
  .content{padding:20px 16px 40px}
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
  .page-hero-title { font-size: 18px; }
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

  <div class="page-hero bc-off">
    <div>
      <div class="page-hero-eyebrow">Liga · Loteria</div>
      <h1 class="page-hero-title"><i class="bi bi-shuffle" style="color:var(--red);margin-right:8px"></i>Loteria do Draft</h1>
      <p class="page-hero-sub">Modelo 3-2-1 anti-tanking: os 16 times fora do playoff disputam as primeiras picks em 4 grupos com chances diferentes.</p>
    </div>
  </div>

  <?php if (!$canRunLottery): ?>
  <div class="section-title bc-off"><i class="bi bi-list-ol"></i> Ordem do draft<?= $confirmedSessionInfo ? ' — Temporada ' . (int)$confirmedSessionInfo['season_number'] : '' ?></div>
  <div class="panel bc-off">
    <?php if (!$confirmedOrder): ?>
    <div class="empty"><i class="bi bi-hourglass-split" style="font-size:22px;display:block;margin-bottom:8px"></i>A ordem do draft desta temporada ainda não foi sorteada. Volte mais tarde.</div>
    <?php else: ?>
    <div class="board">
      <?php foreach ($confirmedOrder as $o): ?>
      <div class="board-slot">
        <span class="board-pos"><?= (int)$o['pick_position'] ?></span>
        <img class="board-logo" src="<?= htmlspecialchars($o['photo_url'] ?: '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
        <span class="board-team"><?= htmlspecialchars($o['team_name']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($canRunLottery): ?>
  <?php if (!$setupSessions): ?>
  <div class="panel bc-off" style="text-align:center">
    <i class="bi bi-info-circle" style="font-size:22px;color:var(--text-3)"></i>
    <p style="margin-top:10px">Nenhuma sessão de draft (ELITE, NEXT, RISE ou ROOKIE) com status "setup" encontrada. Crie a sessão
    de draft da próxima temporada primeiro (na tela de Draft) antes de sortear a ordem de verdade.</p>
  </div>
  <?php else: ?>

  <?php
    /* COM UM DRAFT SÓ, NÃO HÁ O QUE ESCOLHER.
       A loteria é sempre do draft que está em configuração. Quando existe um
       só — que é o caso normal, e sempre o caso de quem administra uma liga —
       o passo "escolha a sessão" era uma pergunta de resposta única: o admin
       tinha que confirmar no seletor aquilo que a tela já sabia.

       O seletor continua existindo (escondido) porque o resto da página lê o
       id dele. E ele volta a aparecer quando há mais de um draft aberto, que
       é quando a pergunta passa a ser de verdade — o admin de várias ligas
       precisa dizer de qual delas é a loteria. */
    $umDraftSo = count($setupSessions) === 1;
    $draftUnico = $umDraftSo ? $setupSessions[0] : null;
  ?>

  <?php if ($umDraftSo): ?>
  <div class="panel bc-off" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
    <div>
      <div style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-3)">
        Draft em configuração
      </div>
      <div style="font-size:16px;font-weight:800;margin-top:2px">
        <?= htmlspecialchars($draftUnico['league']) ?> · Temporada <?= (int)$draftUnico['season_number'] ?><?php
          if ($draftUnico['year']): ?> <span style="color:var(--text-3);font-weight:600">(<?= htmlspecialchars($draftUnico['year']) ?>)</span><?php endif; ?>
      </div>
    </div>
    <button class="btn-red" id="btnPrepare"><i class="bi bi-dice-5-fill"></i> Sortear a loteria</button>
  </div>
  <select id="sessionSelect" style="display:none">
    <option value="<?= (int)$draftUnico['id'] ?>" selected></option>
  </select>

  <?php else: ?>
  <div class="section-title bc-off"><i class="bi bi-calendar2-check"></i> 1. Escolha a sessão de draft</div>
  <div class="panel bc-off">
    <div class="form-row">
      <div class="form-field">
        <label>Sessão de draft</label>
        <select id="sessionSelect">
          <?php foreach ($setupSessions as $s): ?>
          <option value="<?= (int)$s['id'] ?>">[<?= htmlspecialchars($s['league']) ?>] Temporada <?= (int)$s['season_number'] ?><?= $s['year'] ? ' (' . htmlspecialchars($s['year']) . ')' : '' ?> — sessão #<?= (int)$s['id'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn-red" id="btnPrepare"><i class="bi bi-dice-5-fill"></i> Sortear a loteria</button>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <div id="resultSection" style="display:none">

    <?php /* Sai da tela no instante em que o sorteio de verdade acontece —
             ver setupBoardAndOdds. Enquanto está aqui, o que se vê embaixo é
             a ordem da campanha, não um resultado. */ ?>
    <div id="previaAviso" class="panel bc-off" style="display:none;border-color:var(--border-red)">
      <i class="bi bi-eye" style="color:var(--red)"></i>
      <b>Prévia</b> — estes são os times que entram na loteria, com o grupo e as chances de cada um.
      A ordem abaixo é a da <b>campanha</b>; nada foi sorteado ainda.
      Clique em <b>Sortear a loteria</b> quando estiver tudo certo.
    </div>

    <div class="section-title bc-off"><i class="bi bi-percent"></i> Chances da loteria (3-2-1)<i class="bi bi-question-circle info-hint" title="Os 16 times fora do playoff entram em 4 grupos. Cada grupo tem uma chance própria de conseguir uma pick no Top 3 e no Top 5. Mostrado ANTES de revelar, pra todos saberem as probabilidades."></i></div>
    <div class="panel bc-off">
      <div style="overflow-x:auto">
        <table class="balls-table" id="ballsTable">
          <thead><tr><th>Time</th><th>Grupo</th><th class="num">Pos</th><th class="num">Top 3</th><th class="num">Top 5</th></tr></thead>
          <tbody id="ballsBody"></tbody>
        </table>
      </div>
    </div>

    <div class="section-title bc-reveal-title"><i class="bi bi-stars"></i> 2. Revelação<i class="bi bi-question-circle info-hint" title="Clique em 'Revelar próxima' para revelar uma pick de cada vez, da última até a #1. O badge mostra se o time subiu ou caiu em relação à posição que teria só pela campanha."></i></div>
    <div class="reveal-stage" id="revealStage">
      <div class="reveal-fx" id="revealFx" aria-hidden="true"></div>
      <div class="reveal-pick" id="revealPickLabel">Pronto para começar</div>
      <div class="reveal-card">
        <div class="ball-machine" id="ballMachine" aria-hidden="true">
          <div class="ball-globe" id="ballGlobe"></div>
        </div>
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

    <div class="section-title bc-board-title"><i class="bi bi-list-ol"></i> Ordem do draft<i class="bi bi-question-circle info-hint" id="boardHint" title="Antes do sorteio, esta é a ordem da campanha — e é ela que define os grupos de bolinhas. Use as setas pra corrigir quem terminou atrás de quem."></i></div>
    <div class="panel bc-board">
      <?php /* Só aparece antes do sorteio: depois de sortear, mexer aqui não
               teria efeito nenhum nas chances — elas já rolaram. */ ?>
      <div id="ordemBar" class="ordem-bar" style="display:none">
        <span id="ordemBarTexto"></span>
        <span class="ordem-bar-acoes">
          <button class="btn-ghost2" id="btnOrdemDesfazer" type="button"><i class="bi bi-arrow-counterclockwise"></i> Desfazer</button>
          <button class="btn-red" id="btnOrdemSalvar" type="button"><i class="bi bi-check-lg"></i> Salvar ordem</button>
        </span>
      </div>
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

    <!-- O que a ordem decidiu além das posições: proteção que passou ou não,
         e onde cada swap parou. Só aparece quando houve algum caso. -->
    <div class="panel" id="painelEventos" style="display:none">
      <div style="font-weight:800;font-size:13px;margin-bottom:10px">
        <i class="bi bi-shield-check"></i> Acordos resolvidos por esta ordem
      </div>
      <div id="eventosCorpo"></div>
      <div style="font-size:11px;color:var(--text-3);margin-top:10px">
        Proteção e swap só se resolvem quando a ordem sai — as picks já foram movidas.
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="section-title bc-off"><i class="bi bi-journal-text"></i> Como funciona</div>
  <div class="panel rules-panel bc-off">
    <details>
      <summary><i class="bi bi-diagram-2"></i> Quem entra na loteria</summary>
      <div class="rules-body">Playoff = os 8 melhores de cada conferência (16 no total). Todos os outros — os 16 times
      fora do playoff (posições 9 a 16 de cada conferência) — entram na loteria e disputam as 16 primeiras picks. Os 16
      do playoff pegam as últimas picks, em ordem inversa do quão longe foram (o campeão escolhe por último).</div>
    </details>
    <details>
      <summary><i class="bi bi-circle-half"></i> Os 4 grupos e as chances</summary>
      <div class="rules-body">Os 16 times de loteria são divididos em 4 grupos (modelo 3-2-1), cada um com bolinhas e
      chances próprias:<br>
      • <strong>3 piores recordes da liga</strong> — 2 bolinhas · 16% Top 3 / 28% Top 5<br>
      • <strong>4º ao 10º pior recorde (fora do play-in)</strong> — 3 bolinhas · 24% / 39% (a <em>maior</em> chance)<br>
      • <strong>Eliminados no play-in (9º e 10º de cada conferência)</strong> — 2 bolinhas · 16% / 28%<br>
      • <strong>Derrotados no 7x8 (os 2 menos ruins)</strong> — 1 bolinha · 8% / 15% (a <em>menor</em> chance)<br>
      Assim o pior time deixa de ser o favorito à Pick 1 — quem tentou competir até o fim é mais premiado.</div>
    </details>
    <details>
      <summary><i class="bi bi-shield-exclamation"></i> Piso de proteção</summary>
      <div class="rules-body">Os 3 piores times não podem cair além da Pick 12; os demais times da loteria podem cair
      até a Pick 16. Se o sorteio esbarrar nessa trava, o ajuste é aplicado e aparece listado.</div>
    </details>
    <details>
      <summary><i class="bi bi-shuffle"></i> Como o sorteio e a revelação acontecem</summary>
      <div class="rules-body">O modelo 3-2-1 anti-tanking vale para as quatro ligas (ELITE, NEXT, RISE e ROOKIE): o pior time
      deixa de ser o favorito à Pick 1. A ordem é sorteada de uma vez no servidor e você revela pick por pick no clique.
      Os times do playoff (8 de cada conferência) ficam travados no fim da ordem.</div>
    </details>
  </div>
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
  const sel = $('sessionSelect');
  const sessionId = sel ? sel.value : '';
  const btn = $('btnPrepare');
  const label = '<i class="bi bi-dice-5-fill"></i> Sortear a loteria';

  if (!sessionId) { alert('Escolha uma sessão de draft.'); return; }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sorteando...';
  try {
    const payload = { action: 'run_lottery', draft_session_id: parseInt(sessionId, 10) };
    const res = await fetch('/api/draft.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!data.success) { alert(data.error || 'Erro ao sortear a loteria.'); return; }
    result = data;
    setupBoardAndOdds(data);
    $('btnConfirm').style.display = '';
    $('resultSection').style.display = 'block';
  } catch (e) {
    alert('Erro ao sortear a loteria.');
  } finally {
    btn.disabled = false;
    // O rótulo depende de já ter sorteado ou não — e o setupBoardAndOdds já
    // decidiu isso. Restaurar o texto fixo aqui desfazia a troca dele.
    const jaSorteou = result && result.preview === false;
    btn.innerHTML = jaSorteou
      ? '<i class="bi bi-arrow-repeat"></i> Sortear de novo'
      : label;
  }
}

const LOGO_FALLBACK = '/img/default-team.png';
let photoById = {};
function logo(url, cls){
  const src = url || LOGO_FALLBACK;
  return `<img class="${cls}" src="${esc(src)}" alt="" loading="lazy" onerror="this.src='${LOGO_FALLBACK}'">`;
}

/* ─── ORDEM DA CAMPANHA, EDITÁVEL NO QUADRO ───────────────────────────────
   Os grupos de bolinhas saem de quem terminou atrás de quem. Quando essa
   lista chega errada do registro da temporada, o time aparece no grupo
   errado — e antes disso só dava pra corrigir voltando ao card Pontuação,
   longe da tela onde o erro é visto.

   Cada movimento vai ao servidor como ordem PROVISÓRIA: ele devolve os
   grupos e as chances recalculados, sem gravar nada. Quem grava é o botão
   Salvar. Enquanto houver mudança pendente o sorteio fica bloqueado, porque
   ele sortearia pela ordem gravada, não pela que está na tela. */
const PODE_EDITAR_ORDEM = <?= $canRunLottery ? 'true' : 'false' ?>;
let ordemLoteria = [];    // origin_team_id na ordem do quadro (pior primeiro)
let ordemSalvaRef = [];   // como estava na última vez que gravou
let ordemPendente = false;

function atualizarBarraOrdem(){
  const bar = $('ordemBar');
  if (!bar) return;
  bar.style.display = ordemPendente ? '' : 'none';
  const txt = $('ordemBarTexto');
  if (txt) txt.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> '
    + '<b>Ordem alterada.</b> As chances acima já refletem a mudança, mas ela ainda não foi gravada — '
    + 'salve antes de sortear.';
  const btnSortear = $('btnPrepare');
  if (btnSortear) {
    btnSortear.disabled = ordemPendente;
    btnSortear.title = ordemPendente ? 'Salve a ordem antes de sortear' : '';
  }
}

function moverOrdem(i, dir){
  const j = i + dir;
  if (i < 0 || j < 0 || j >= ordemLoteria.length) return;
  const nova = ordemLoteria.slice();
  [nova[i], nova[j]] = [nova[j], nova[i]];
  ordemPendente = true;
  carregarPrevia(nova);
}

function desfazerOrdem(){
  ordemPendente = false;
  carregarPrevia();   // sem ordem provisória: volta ao que está gravado
}

async function salvarOrdem(){
  if (!result || !result.standings_season_id) return;
  const btn = $('btnOrdemSalvar');
  const rotulo = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Salvando...';
  try {
    const res = await fetch('/api/draft.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'save_lottery_order',
        season_id: result.standings_season_id,
        ordem: ordemLoteria
      })
    });
    const data = await res.json();
    if (!data.success) { alert(data.error || 'Não foi possível gravar a ordem.'); return; }
    ordemPendente = false;
    await carregarPrevia();   // relê do banco: o que a tela mostra é o que está gravado
  } catch (e) {
    alert('Não foi possível gravar a ordem.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = rotulo;
  }
}

function setupBoardAndOdds(data){
  revealed = new Set();
  busy = false;
  photoById = {};
  /* Sorteou de verdade: o aviso de prévia sai e o botão do topo muda de
     nome. Ele NÃO some: o "Sortear de novo" só aparece quando a revelação
     termina, e esconder os dois deixaria sem saída quem sorteou a sessão
     errada e quer refazer no ato. Some do botão só a inocência — a partir
     daqui ele pergunta antes, porque jogar fora um sorteio que a liga já
     está vendo revelar não pode acontecer por um clique distraído. */
  const avisoPrevia = $('previaAviso');
  if (avisoPrevia) avisoPrevia.style.display = data.preview ? '' : 'none';
  const btnSortear = $('btnPrepare');
  if (btnSortear) {
    btnSortear.innerHTML = data.preview
      ? '<i class="bi bi-dice-5-fill"></i> Sortear a loteria'
      : '<i class="bi bi-arrow-repeat"></i> Sortear de novo';
  }
  data.order.forEach(o => { photoById[o.team_id] = o.photo_url; });

  // Chances (antes de revelar) — agrupadas pelos 4 grupos do modelo 3-2-1
  $('ballsBody').innerHTML = data.balls.map(b => `
    <tr>
      <td><span style="display:inline-flex;align-items:center;gap:8px">${logo(b.photo_url,'board-logo')}${esc(b.team_name)}${b.conference ? `<span class="conf-chip">${esc(b.conference)}</span>` : ''}</span></td>
      <td><span class="conf-chip" title="${b.balls} bolinha(s)">${esc(b.group_label || '')}</span></td>
      <td class="num">${b.position_anterior}º</td>
      <td class="num">${b.top3_pct}%</td>
      <td class="num">${b.top5_pct}%</td>
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
      <div class="bowl-odds" title="Chance de Top 5: ${b.top5_pct}%">${b.top3_pct}% <span style="font-size:9px;color:var(--text-3);font-weight:600">Top 3</span></div>
      <div class="bowl-pos">${b.position_anterior}º ${esc(b.conference || '')}</div>
    </div>
  `).join('');
  updateBowlCount(lotteryTeams.length);

  /* QUADRO. Antes do sorteio ele mostra a ordem da CAMPANHA e deixa o admin
     corrigi-la, porque é dela que saem os grupos de bolinhas: um time no
     lugar errado aqui recebe a chance errada lá em cima. Depois do sorteio a
     ordem é resultado, e volta a ser o quadro de sempre — loteria oculta até
     a revelação, playoff travado embaixo. */
  const editandoOrdem = !!data.preview && PODE_EDITAR_ORDEM;
  if (data.preview) {
    ordemLoteria = data.order.filter(o => o.source !== 'playoff').map(o => o.origin_team_id);
    if (!ordemPendente) ordemSalvaRef = ordemLoteria.slice();
  } else {
    // Sorteado: a ordem virou resultado e não se edita mais.
    ordemPendente = false;
  }
  atualizarBarraOrdem();

  $('board').innerHTML = data.order.map(o => {
    const isPlayoff = o.source === 'playoff';
    const top4 = o.position <= 4 ? ' top4' : '';
    if (!isPlayoff && editandoOrdem) {
      const i = ordemLoteria.indexOf(o.origin_team_id);
      const mudou = ordemSalvaRef[i] !== undefined && ordemSalvaRef[i] !== o.origin_team_id;
      return `<div class="board-slot editavel${top4}${mudou ? ' movido' : ''}" id="board-slot-${o.position}">
        <span class="board-pos">${o.position}</span>
        ${logo(o.photo_url,'board-logo')}
        <span class="board-team">${esc(o.team_name)}${o.is_swap ? ' ' + viaTag(o) : ''}</span>
        <span class="board-move">
          <button type="button" title="Subir (campanha pior)" onclick="moverOrdem(${i},-1)"${i === 0 ? ' disabled' : ''}><i class="bi bi-caret-up-fill"></i></button>
          <button type="button" title="Descer (campanha melhor)" onclick="moverOrdem(${i},1)"${i === ordemLoteria.length - 1 ? ' disabled' : ''}><i class="bi bi-caret-down-fill"></i></button>
        </span>
      </div>`;
    }
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
  $('ballMachine')?.classList.remove('on');
  $('revealLogo').style.display = '';
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

const REDUCED_MOTION = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Cerimônia da bolinha: joga no globo uma bolinha por time ainda na urna,
 * gira todas, desacelera e "puxa" a do time sorteado. O resultado já veio
 * pronto do servidor — isto é só a encenação. Chama onDone() no fim.
 */
function spinBalls(pool, entry, onDone){
  const machine = $('ballMachine'), globe = $('ballGlobe');
  if (!machine || !globe || REDUCED_MOTION) { onDone(); return; }

  globe.classList.remove('slowing');
  globe.innerHTML = '';
  machine.classList.add('on'); // precisa estar visível antes de medir o raio

  const raio = Math.max(Math.min(machine.clientWidth, machine.clientHeight) / 2 - 26, 20);
  globe.innerHTML = pool.map(o => {
    // Órbitas e velocidades diferentes por bolinha = movimento de urna, não de carrossel.
    const r = Math.round(10 + Math.random() * raio);
    const dur = (1.0 + Math.random() * 0.9).toFixed(2);
    const delay = (-Math.random() * 2).toFixed(2);
    const dir = Math.random() < 0.5 ? 'normal' : 'reverse';
    return `<div class="lottery-ball" id="lb-${o.team_id}" style="--r:${r}px;animation-duration:${dur}s;animation-delay:${delay}s;animation-direction:${dir}">
      <img src="${esc(o.photo_url || LOGO_FALLBACK)}" alt="" onerror="this.src='${LOGO_FALLBACK}'">
    </div>`;
  }).join('');

  const giro = 1600 + Math.random() * 700;
  setTimeout(() => {
    globe.classList.add('slowing');
    const bola = document.getElementById('lb-' + entry.team_id);
    if (bola) bola.classList.add('drawn');
    setTimeout(() => {
      machine.classList.remove('on');
      onDone();
    }, bola ? 820 : 200);
  }, giro);
}

function revealNext(){
  if (busy || !revealQueue.length) return;
  busy = true;
  const pos = revealQueue.shift();
  const entry = result.order.find(o => o.position === pos);
  const btn = $('btnReveal');
  btn.disabled = true;
  $('revealStage').classList.add('armed');

  // decoys = times de loteria ainda não revelados (as outras bolinhas do globo)
  const decoys = result.order
    .filter(o => o.source !== 'playoff' && !revealed.has(o.position) && o.position !== pos);

  const numEl = $('revealNumber'), teamEl = $('revealTeam'), confEl = $('revealConf'), moveEl = $('revealMove'), logoEl = $('revealLogo'), passedEl = $('revealPassed');
  numEl.className = 'reveal-number on';
  numEl.textContent = '#' + pos;
  confEl.textContent = '';
  moveEl.className = 'reveal-move';
  moveEl.textContent = '';
  passedEl.className = 'reveal-passed';
  passedEl.innerHTML = '';
  $('revealVia').innerHTML = '';
  // O nome e o escudo só aparecem depois que a bolinha sai do globo. Aqui é
  // display:none (e não visibility) para o escudo não deixar um vão de 120px
  // entre o globo e o número da pick enquanto as bolinhas giram.
  teamEl.className = 'reveal-team q';
  teamEl.textContent = '';
  logoEl.className = 'reveal-logo';
  logoEl.style.display = 'none';

  // Todas as bolinhas que ainda estão na urna, com a sorteada entre elas.
  spinBalls([entry].concat(decoys), entry, land);

  function land(){
    logoEl.style.display = '';
    logoEl.style.visibility = 'visible';
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
}

async function confirmOrder(){
  if (!result) return;
  if (!await confirmarSite('Confirmar essa ordem e aplicar ao draft? Isso substitui qualquer ordem já definida para as duas rodadas dessa sessão.')) return;
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
    mostrarEventos(data.eventos || []);
  } catch (e) {
    alert('Erro ao aplicar a ordem.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check-lg"></i> Confirmar e aplicar ao draft';
  }
}

/**
 * O que a loteria decidiu além da ordem.
 *
 * Proteção e swap só se resolvem QUANDO a ordem sai — antes disso são
 * apostas. Se isso não for mostrado agora, ninguém mais vai saber que
 * houve acordo: a ordem final mostra o resultado sem a explicação, e a
 * pergunta "por que o Coyotes escolhe na vaga do Kings?" fica sem resposta.
 *
 * Sem nada a dizer, volta o alerta simples de sempre.
 */
function mostrarEventos(eventos) {
  if (!eventos.length) { alert('Ordem aplicada com sucesso ao draft!'); return; }

  const cx = document.getElementById('painelEventos');
  const corpo = document.getElementById('eventosCorpo');
  if (!cx || !corpo) {
    alert('Ordem aplicada!\n\n' + eventos.map(e => '• ' + e.texto + (e.extra ? '\n  ' + e.extra : '')).join('\n'));
    return;
  }

  const escE = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  corpo.innerHTML = eventos.map((e) => `
    <div class="ev ${e.tipo}${e.passou ? '' : ' barrado'}">
      <div class="ev-tag">${e.tipo === 'swap' ? 'SWAP' : (e.passou ? 'PASSOU' : 'PROTEGIDA')}</div>
      <div>
        <div class="ev-txt">${escE(e.texto)}</div>
        ${e.extra ? `<div class="ev-extra">${escE(e.extra)}</div>` : ''}
      </div>
    </div>`).join('');
  cx.style.display = 'block';
  cx.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Quem não administra loteria nenhuma não tem esses controles na página
// (só vê a ordem já confirmada), então todos os binds ficam guardados.
// A partir do segundo sorteio o clique descarta um resultado que já existe —
// e que pode estar sendo revelado na frente da liga. Pergunta antes.
if ($('btnPrepare')) $('btnPrepare').addEventListener('click', () => {
  const jaSorteou = result && result.preview === false;
  if (jaSorteou && !confirm('Sortear de novo? A ordem que está na tela é descartada e uma nova é sorteada do zero.')) return;
  prepare();
});
if ($('btnReveal')) $('btnReveal').addEventListener('click', revealNext);
if ($('btnConfirm')) $('btnConfirm').addEventListener('click', confirmOrder);
// Refazer joga fora um sorteio que já aconteceu — e que a liga pode já ter
// visto sendo revelado. Pergunta antes.
if ($('btnRedo')) $('btnRedo').addEventListener('click', () => {
  if (confirm('Sortear de novo? A ordem que está na tela é descartada e uma nova é sorteada do zero.')) prepare();
});

/* A PRÉVIA CARREGA SOZINHA.
   Quem abre esta tela quer ver quem entra na loteria, em que grupo e com
   quantas bolinhas — e até agora isso só aparecia depois de apertar o botão,
   que já é o sorteio. Então o quadro vem montado de saída, com a ordem
   natural (pior campanha primeiro) e as chances de cada um.

   É PRÉVIA, não sorteio: pedir o sorteio pra preencher a tela faria sair uma
   ordem nova a cada vez que a página abrisse, e a que vale seria a última —
   o admin veria um resultado que não é o resultado. */
async function carregarPrevia(ordemProvisoria){
  const sel = $('sessionSelect');
  if (!sel || !sel.value) return;
  try {
    const corpo = { action: 'run_lottery', draft_session_id: parseInt(sel.value, 10), preview: true };
    // Ordem ainda não gravada: o servidor recalcula os grupos com ela e
    // devolve as chances de verdade, em vez de a tela adivinhar a regra.
    if (Array.isArray(ordemProvisoria)) corpo.ordem = ordemProvisoria;
    const res = await fetch('/api/draft.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(corpo)
    });
    const data = await res.json();
    if (!data.success) return;         // sem classificação lançada ainda, por exemplo
    result = data;
    setupBoardAndOdds(data);
    $('resultSection').style.display = 'block';
    // Confirmar só existe depois de sortear de verdade.
    if ($('btnConfirm')) $('btnConfirm').style.display = 'none';
    const aviso = $('previaAviso');
    if (aviso) aviso.style.display = '';
  } catch (e) { /* a tela continua servindo pelo botão */ }
}
// Trocar de sessão zera a edição pendente: a ordem é de outra temporada.
if ($('sessionSelect')) $('sessionSelect').addEventListener('change', () => { ordemPendente = false; carregarPrevia(); });
$('btnOrdemSalvar')?.addEventListener('click', salvarOrdem);
$('btnOrdemDesfazer')?.addEventListener('click', desfazerOrdem);
carregarPrevia();
</script>
</body>
</html>
