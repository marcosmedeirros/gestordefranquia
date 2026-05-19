<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require '../core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = (int)$_SESSION['user_id'];

// diff: 1=Iniciante 2=Médio 3=Difícil 4=Elite
$TIMES = [
  ['slug'=>'brasil',    'name'=>'Brasil',       'badge'=>'BRA','color'=>'#FFD700','dark'=>'#009c3b',
   'shirt'=>'#FFD700','shirt2'=>'#009c3b', 'diff'=>1,
   'gk'=>'Taffarel',        'gk_def'=>6, 'player'=>'Pelé',           'player_shot'=>6],
  ['slug'=>'alemanha',  'name'=>'Alemanha',     'badge'=>'GER','color'=>'#dddddd','dark'=>'#333333',
   'shirt'=>'#dddddd','shirt2'=>'#333333', 'diff'=>1,
   'gk'=>'Oliver Kahn',     'gk_def'=>6, 'player'=>'F. Beckenbauer', 'player_shot'=>7],
  ['slug'=>'italia',    'name'=>'Itália',       'badge'=>'ITA','color'=>'#0057A8','dark'=>'#003d7a',
   'shirt'=>'#0057A8','shirt2'=>'#ffffff', 'diff'=>1,
   'gk'=>'G. Buffon',       'gk_def'=>7, 'player'=>'Paolo Maldini',  'player_shot'=>6],
  ['slug'=>'argentina', 'name'=>'Argentina',    'badge'=>'ARG','color'=>'#74ACDF','dark'=>'#3a87c8',
   'shirt'=>'#74ACDF','shirt2'=>'#ffffff', 'diff'=>1,
   'gk'=>'S. Goycochea',    'gk_def'=>6, 'player'=>'Diego Maradona', 'player_shot'=>7],
  ['slug'=>'franca',    'name'=>'França',       'badge'=>'FRA','color'=>'#003DA5','dark'=>'#001f7a',
   'shirt'=>'#003DA5','shirt2'=>'#ffffff', 'diff'=>2,
   'gk'=>'Hugo Lloris',     'gk_def'=>7, 'player'=>'Z. Zidane',      'player_shot'=>7],
  ['slug'=>'uruguai',   'name'=>'Uruguai',      'badge'=>'URU','color'=>'#5EB6E4','dark'=>'#2a9de0',
   'shirt'=>'#5EB6E4','shirt2'=>'#ffffff', 'diff'=>2,
   'gk'=>'F. Muslera',      'gk_def'=>7, 'player'=>'Luis Suárez',    'player_shot'=>7],
  ['slug'=>'espanha',   'name'=>'Espanha',      'badge'=>'ESP','color'=>'#AA151B','dark'=>'#8a0613',
   'shirt'=>'#AA151B','shirt2'=>'#F1BF00', 'diff'=>2,
   'gk'=>'Iker Casillas',   'gk_def'=>7, 'player'=>'A. Iniesta',     'player_shot'=>8],
  ['slug'=>'inglaterra','name'=>'Inglaterra',   'badge'=>'ENG','color'=>'#f0f0f0','dark'=>'#cc0000',
   'shirt'=>'#f0f0f0','shirt2'=>'#cc0000', 'diff'=>2,
   'gk'=>'Gordon Banks',    'gk_def'=>7, 'player'=>'B. Charlton',    'player_shot'=>8],
  ['slug'=>'holanda',   'name'=>'Holanda',      'badge'=>'NED','color'=>'#F36C21','dark'=>'#cc4400',
   'shirt'=>'#F36C21','shirt2'=>'#ffffff', 'diff'=>3,
   'gk'=>'E. van der Sar',  'gk_def'=>8, 'player'=>'Johan Cruyff',   'player_shot'=>7],
  ['slug'=>'portugal',  'name'=>'Portugal',     'badge'=>'POR','color'=>'#D20000','dark'=>'#006600',
   'shirt'=>'#D20000','shirt2'=>'#006600', 'diff'=>3,
   'gk'=>'Vítor Baía',      'gk_def'=>8, 'player'=>'C. Ronaldo',     'player_shot'=>8],
  ['slug'=>'croacia',   'name'=>'Croácia',      'badge'=>'CRO','color'=>'#CC2222','dark'=>'#991111',
   'shirt'=>'#CC2222','shirt2'=>'#ffffff', 'diff'=>3,
   'gk'=>'D. Livaković',    'gk_def'=>8, 'player'=>'Luka Modrić',    'player_shot'=>8],
  ['slug'=>'belgica',   'name'=>'Bélgica',      'badge'=>'BEL','color'=>'#EF3340','dark'=>'#b31525',
   'shirt'=>'#EF3340','shirt2'=>'#000000', 'diff'=>3,
   'gk'=>'T. Courtois',     'gk_def'=>8, 'player'=>'Eden Hazard',    'player_shot'=>9],
  ['slug'=>'suecia',    'name'=>'Suécia',       'badge'=>'SWE','color'=>'#006AA7','dark'=>'#004d7a',
   'shirt'=>'#FECC02','shirt2'=>'#006AA7', 'diff'=>4,
   'gk'=>'T. Ravelli',      'gk_def'=>9, 'player'=>'Z. Ibrahimović', 'player_shot'=>8],
  ['slug'=>'mexico',    'name'=>'México',       'badge'=>'MEX','color'=>'#006847','dark'=>'#00432d',
   'shirt'=>'#006847','shirt2'=>'#ffffff', 'diff'=>4,
   'gk'=>'Jorge Campos',    'gk_def'=>9, 'player'=>'Hugo Sánchez',   'player_shot'=>8],
  ['slug'=>'colombia',  'name'=>'Colômbia',     'badge'=>'COL','color'=>'#FCD116','dark'=>'#c9a800',
   'shirt'=>'#FCD116','shirt2'=>'#003087', 'diff'=>4,
   'gk'=>'René Higuita',    'gk_def'=>9, 'player'=>'C. Valderrama',  'player_shot'=>9],
  ['slug'=>'tcheca',    'name'=>'Rep. Tcheca',  'badge'=>'CZE','color'=>'#D7141A','dark'=>'#9e0d10',
   'shirt'=>'#D7141A','shirt2'=>'#ffffff', 'diff'=>4,
   'gk'=>'Petr Čech',       'gk_def'=>9, 'player'=>'Pavel Nedvěd',   'player_shot'=>9],
];

// Auto-create tables
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS penalti_desbloqueados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        team_slug VARCHAR(50) NOT NULL,
        desbloqueado_em DATETIME DEFAULT NOW(),
        UNIQUE KEY uk_pd (id_usuario, team_slug)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS penalti_conquistas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        team_slug VARCHAR(50) NOT NULL,
        conquistado_em DATETIME DEFAULT NOW(),
        UNIQUE KEY uk_pc (id_usuario, team_slug)
    ) DEFAULT CHARSET=utf8mb4");
} catch(PDOException $e) {}

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    $validSlugs = array_column($TIMES, 'slug');

    if ($action === 'comprar_time') {
        $slug = $body['slug'] ?? '';
        if (!in_array($slug, $validSlugs)) { echo json_encode(['ok'=>false,'msg'=>'Time inválido']); exit; }
        $stmt = $pdo->prepare("SELECT id FROM penalti_desbloqueados WHERE id_usuario=? AND team_slug=?");
        $stmt->execute([$user_id, $slug]);
        if ($stmt->fetch()) { echo json_encode(['ok'=>true,'already'=>true]); exit; }
        $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id=?");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ((int)($u['pontos'] ?? 0) < 1000) { echo json_encode(['ok'=>false,'msg'=>'Moedas insuficientes']); exit; }
        $pdo->prepare("UPDATE usuarios SET pontos=pontos-1000 WHERE id=?")->execute([$user_id]);
        $pdo->prepare("INSERT IGNORE INTO penalti_desbloqueados (id_usuario,team_slug) VALUES (?,?)")->execute([$user_id,$slug]);
        $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id=?");
        $stmt->execute([$user_id]);
        $newM = (int)($stmt->fetch(PDO::FETCH_ASSOC)['pontos'] ?? 0);
        echo json_encode(['ok'=>true,'moedas'=>$newM]); exit;
    }

    if ($action === 'registrar_conquista') {
        $slug = $body['slug'] ?? '';
        if (!in_array($slug, $validSlugs)) { echo json_encode(['ok'=>false]); exit; }
        $stmtChk = $pdo->prepare("SELECT id FROM penalti_conquistas WHERE id_usuario=? AND team_slug=?");
        $stmtChk->execute([$user_id, $slug]);
        $jaConquistou = (bool)$stmtChk->fetch();
        $pdo->prepare("INSERT IGNORE INTO penalti_conquistas (id_usuario,team_slug) VALUES (?,?)")->execute([$user_id,$slug]);
        $newMoedas = null;
        if (!$jaConquistou) {
            $pdo->prepare("UPDATE usuarios SET pontos=pontos+500 WHERE id=?")->execute([$user_id]);
            $s = $pdo->prepare("SELECT pontos FROM usuarios WHERE id=?");
            $s->execute([$user_id]);
            $newMoedas = (int)($s->fetch(PDO::FETCH_ASSOC)['pontos'] ?? 0);
        }
        echo json_encode(['ok'=>true,'moedas'=>$newMoedas,'primeira_vez'=>!$jaConquistou]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação inválida']); exit;
}

// Load user data
$desbloqueados   = [];
$conquistas_arr  = [];
$moedas          = 0;
$hall_fama       = [];
try {
    $s = $pdo->prepare("SELECT team_slug FROM penalti_desbloqueados WHERE id_usuario=?");
    $s->execute([$user_id]);
    $desbloqueados = array_column($s->fetchAll(PDO::FETCH_ASSOC),'team_slug');
} catch(PDOException $e) {}
if (!in_array('brasil', $desbloqueados)) $desbloqueados[] = 'brasil';
try {
    $s = $pdo->prepare("SELECT team_slug FROM penalti_conquistas WHERE id_usuario=?");
    $s->execute([$user_id]);
    $conquistas_arr = array_column($s->fetchAll(PDO::FETCH_ASSOC),'team_slug');
} catch(PDOException $e) {}
try {
    $s = $pdo->prepare("SELECT nome, pontos, is_admin, fba_points FROM usuarios WHERE id=?");
    $s->execute([$user_id]);
    $usuario = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    $moedas  = (int)($usuario['pontos'] ?? 0);
} catch(PDOException $e) { $usuario = []; }
try {
    $s = $pdo->query(
        "SELECT u.nome, MAX(pc.conquistado_em) AS completado_em
         FROM penalti_conquistas pc
         JOIN usuarios u ON u.id = pc.id_usuario
         GROUP BY pc.id_usuario, u.nome
         HAVING COUNT(*) >= 16
         ORDER BY completado_em ASC
         LIMIT 5"
    );
    $hall_fama = $s ? $s->fetchAll(PDO::FETCH_ASSOC) : [];
} catch(PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#c8001e">
<title>Copa do Mundo 2026 · Pênaltis</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.06);--border2:rgba(255,255,255,.10);
  --text:#f0f0f3;--text2:#868690;--text3:#48484f;
  --green:#22c55e;--red:#ef4444;--amber:#f59e0b;--blue:#3b82f6;--gold:#ffd700;
  --brand:#fc0025;--brand-soft:rgba(252,0,37,.10);--border-brand:rgba(252,0,37,.22);
  --font:'Poppins',system-ui,sans-serif;--radius:14px;--t:200ms;--ease:cubic-bezier(.2,.8,.2,1);
}
body{background:var(--bg);color:var(--text);font-family:var(--font);min-height:100vh;overflow-x:hidden;-webkit-tap-highlight-color:transparent;-webkit-font-smoothing:antialiased}

/* ── TOPBAR ── */
.topbar{position:sticky;top:0;z-index:200;height:52px;background:var(--panel);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;padding:0 16px}
.topbar-back{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text2);display:flex;align-items:center;justify-content:center;font-size:15px;text-decoration:none;transition:all var(--t) var(--ease);flex-shrink:0}
.topbar-back:hover{background:var(--brand-soft);border-color:var(--border-brand);color:var(--brand)}
.topbar-title{font-size:14px;font-weight:800;color:var(--text);flex:1}
.topbar-title span{color:var(--brand)}
.topbar-chip{display:flex;align-items:center;gap:5px;background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:4px 11px;font-size:11px;font-weight:700;color:var(--text2);flex-shrink:0}
.topbar-chip i{font-size:10px;color:var(--amber)}

/* ── SCREENS ── */
.screen{display:none;max-width:540px;margin:0 auto;padding:0 0 80px}
.screen.active{display:block}

/* ── CAMPAIGN ── */
.camp-header{padding:20px 14px 10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.camp-title{font-size:18px;font-weight:800;color:var(--text);letter-spacing:-.3px}
.camp-progress{font-size:11px;color:var(--text2)}
.camp-progress strong{color:var(--gold)}
.camp-bar-wrap{padding:0 14px 14px}
.camp-bar-bg{background:var(--panel2);border-radius:99px;height:6px;overflow:hidden}
.camp-bar-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--gold),#ff9900);transition:width .5s ease}

.camp-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;padding:0 14px 14px}
@media(max-width:400px){.camp-grid{grid-template-columns:repeat(3,1fr)}}
.camp-card{border-radius:12px;padding:10px 6px 8px;cursor:pointer;text-align:center;border:2px solid;transition:.15s;position:relative;overflow:hidden}
.camp-card:hover{transform:translateY(-2px)}
.camp-card.locked{background:var(--panel);border-color:var(--border);filter:grayscale(1);opacity:.6}
.camp-card.locked:hover{opacity:.8;filter:grayscale(.7)}
.camp-card.unlocked{background:var(--panel)}
.camp-card.conquered{background:var(--panel)}
.camp-badge{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:9px;font-size:9px;font-weight:800;margin-bottom:4px;letter-spacing:.3px}
.camp-name{font-size:9px;font-weight:700;color:var(--text);line-height:1.2;margin-bottom:4px}
.camp-price{font-size:9px;color:var(--gold);font-weight:700;margin-bottom:3px}
.camp-lock{font-size:14px;color:var(--text3)}
.camp-trophy{position:absolute;top:4px;right:4px;font-size:12px}
.camp-players{font-size:8px;color:var(--text3);line-height:1.6}
.camp-conquered-lbl{font-size:9px;color:var(--gold);font-weight:700}
.camp-play-btn{display:inline-block;margin-top:4px;font-size:8px;font-weight:700;color:var(--green);border:1px solid rgba(34,197,94,.3);border-radius:4px;padding:2px 6px}

/* Mystery reward */
.mystery-card{margin:0 14px 20px;background:linear-gradient(135deg,#0d1a3a,#1a0d3a);border:1px solid rgba(255,215,0,.25);border-radius:14px;padding:20px 16px;text-align:center;position:relative;overflow:hidden}
.mystery-card::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 50% 0%,rgba(255,215,0,.07),transparent 70%)}
.mystery-q{font-size:52px;font-weight:900;color:rgba(255,215,0,.5);line-height:1;margin-bottom:6px;text-shadow:0 0 30px rgba(255,215,0,.3)}
.mystery-title{font-size:13px;font-weight:800;color:var(--gold);margin-bottom:6px;letter-spacing:.5px}
.mystery-sub{font-size:11px;color:var(--text3);line-height:1.6}
.mystery-locks{font-size:13px;letter-spacing:4px;margin-top:8px;opacity:.4}

/* ── BUTTONS ── */
.btn{border:none;border-radius:12px;padding:13px 20px;font-size:14px;font-weight:700;cursor:pointer;width:100%;transition:.15s;letter-spacing:.3px}
.btn-primary{background:linear-gradient(135deg,var(--brand),#c8001e);color:#fff;box-shadow:0 4px 14px rgba(252,0,37,.35)}
.btn-primary:hover{filter:brightness(1.1)}
.btn-success{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;box-shadow:0 4px 14px rgba(22,163,74,.4)}
.btn-danger{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff}
.btn-ghost{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text2)}
.btn-gold{background:linear-gradient(135deg,#d97706,#b45309);color:#fff;box-shadow:0 4px 14px rgba(217,119,6,.4)}
.btn-ghost:hover{background:rgba(255,255,255,.10);color:var(--text)}
.btn-sm{padding:8px 16px;font-size:12px;border-radius:8px;width:auto}

/* ── GROUPS SCREEN ── */
.groups-wrap{padding:12px}
.all-groups{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}
.group-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px}
.group-card.user-group{border-color:rgba(245,158,11,.5);background:rgba(245,158,11,.04)}
.group-hdr{font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between}
.group-hdr .live-dot{width:7px;height:7px;border-radius:50%;background:var(--red);animation:pulse 1s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.g-team{display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.g-team:last-child{border-bottom:none}
.g-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.g-pos{font-size:10px;font-weight:700;color:var(--text3);min-width:14px;text-align:center}
.g-name{font-size:12px;font-weight:600;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.g-name.user-team{color:var(--amber)}
.g-pts{font-size:12px;font-weight:700;color:var(--amber);min-width:22px;text-align:right}
.g-saldo{font-size:10px;color:var(--text3);min-width:28px;text-align:center}
.g-pld{font-size:10px;color:var(--text3);min-width:18px;text-align:center}
.match-live-feed{background:var(--panel2);border:1px solid var(--border);border-radius:10px;padding:8px 12px;margin-bottom:10px;font-size:11px;color:var(--text2);min-height:32px}
.live-result{animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
.classif{display:inline-block;font-size:9px;padding:1px 5px;border-radius:4px;font-weight:700;margin-left:4px}
.classif.q{background:rgba(34,197,94,.2);color:var(--green)}
.classif.e{background:rgba(239,68,68,.15);color:var(--red)}

/* ── FIELD ── */
.field-scene{position:relative;width:100%;background:linear-gradient(180deg,#87CEEB 0%,#b0e0ff 30%,#4a8a4a 30.1%,#3a7a3a 60%,#2d6a2d 100%);overflow:hidden;border-radius:14px 14px 0 0}
.crowd{width:100%;height:30px;background:linear-gradient(90deg,#b44,#48b,#4b8,#b84,#84b,#4bb,#bb4,#b44);opacity:.7;position:relative}
.crowd::after{content:'';position:absolute;inset:0;background:repeating-linear-gradient(90deg,rgba(0,0,0,.15) 0px,rgba(0,0,0,.15) 2px,transparent 2px,transparent 8px)}
.sky{height:20px;background:linear-gradient(180deg,#1a3a6a,#2a5a9a)}
.grass-top{height:8px;background:#3a8a3a;border-bottom:2px solid rgba(255,255,255,.3)}
.goal-container{position:relative;max-width:320px;margin:0 auto;padding:8px 10px 0}
.goal-svg{width:100%;display:block;filter:drop-shadow(0 4px 12px rgba(0,0,0,.5))}
.kicker-container{position:relative;max-width:320px;margin:-10px auto 0;height:90px;display:flex;align-items:flex-end;justify-content:center}
.kicker-svg{width:70px;height:90px;display:block;filter:drop-shadow(0 3px 6px rgba(0,0,0,.4))}
.kicker-svg.kick-anim{animation:kickAnim .35s ease forwards}
@keyframes kickAnim{0%{transform:translateY(0) rotate(0deg)}30%{transform:translateY(-6px) rotate(-5deg)}60%{transform:translateY(-4px) rotate(3deg)}100%{transform:translateY(0) rotate(0deg)}}

/* ── MATCH PANEL ── */
.match-panel{background:var(--panel);padding:0;border-radius:0 0 14px 14px;overflow:hidden}
.match-top{background:linear-gradient(135deg,var(--panel2),var(--panel3));padding:10px 14px;display:flex;align-items:center;gap:10px}
.mt-team{flex:1;text-align:center}
.mt-name{font-size:11px;font-weight:700;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mt-attrs{font-size:10px;color:var(--text);margin-top:3px;line-height:1.6;font-weight:500}
.mt-score{font-size:32px;font-weight:900;color:#fff;letter-spacing:3px;min-width:80px;text-align:center;font-variant-numeric:tabular-nums}
.mt-round{font-size:10px;color:var(--text3);text-align:center}
.dots-row{display:flex;align-items:center;gap:6px;padding:6px 14px 4px}
.dots-label{font-size:10px;color:var(--text3);min-width:28px;font-weight:700}
.dot{width:20px;height:20px;border-radius:50%;border:2px solid rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0;transition:.2s}
.dot.dot-scored{background:var(--green);border-color:var(--green)}
.dot.dot-missed{background:rgba(239,68,68,.3);border-color:var(--red)}
.dot.pending{opacity:.25}
.status-bar{text-align:center;padding:6px 12px 2px;font-size:13px;font-weight:700;min-height:28px}
.s-ok{color:var(--green)}.s-fail{color:var(--red)}.s-neutral{color:var(--amber)}.s-info{color:var(--blue)}
.phase-tag{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px}
.phase-tag.shoot{background:rgba(245,158,11,.2);color:var(--amber)}
.phase-tag.defend{background:rgba(59,130,246,.2);color:var(--blue)}
.phase-tag.sd{background:rgba(239,68,68,.2);color:var(--red)}

/* ── ZONES ── */
.zone-grid{display:grid;grid-template-columns:1fr 1fr 1fr;grid-template-rows:1fr 1fr;gap:3px;padding:8px 12px 10px;max-width:380px;margin:0 auto}
.zone-btn{background:rgba(255,255,255,.07);border:2px solid rgba(255,255,255,.15);border-radius:10px;height:52px;cursor:pointer;transition:.15s;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;position:relative;overflow:hidden}
.zone-btn:hover{background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.4);transform:scale(1.03)}
.zone-btn.hit-goal{border-color:var(--green)!important;background:rgba(34,197,94,.25)!important;animation:goalFlash .4s ease}
.zone-btn.hit-save{border-color:var(--red)!important;background:rgba(239,68,68,.2)!important}
@keyframes goalFlash{0%,100%{opacity:1}50%{opacity:.6}}
.zone-icon{font-size:16px}.zone-lbl{font-size:8px;color:rgba(255,255,255,.5)}

/* ── RESULT / KO / CHAMP ── */
.result-overlay{position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:300;display:flex;align-items:center;justify-content:center;padding:20px}
.result-box{background:var(--panel);border:1px solid var(--border2);border-radius:20px;padding:28px 22px;max-width:360px;width:100%;text-align:center;font-family:var(--font)}
.result-big-icon{font-size:58px;margin-bottom:8px}
.result-title{font-size:22px;font-weight:900;margin-bottom:4px}
.result-score-display{font-size:28px;font-weight:800;margin:10px 0;color:#fff}
.ko-wrap{padding:12px}
.ko-match{background:var(--panel);border:1px solid var(--border);border-radius:12px;margin-bottom:8px;overflow:hidden}
.ko-match.user-match{border-color:rgba(245,158,11,.5)}
.ko-team-row{display:flex;align-items:center;gap:8px;padding:8px 12px}
.ko-team-row+.ko-team-row{border-top:1px solid var(--border)}
.ko-badge{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;flex-shrink:0}
.ko-name{flex:1;font-size:12px;font-weight:600}
.ko-tier{font-size:9px;color:var(--text3)}
.user-lbl{font-size:9px;font-weight:700;color:var(--amber);background:rgba(245,158,11,.12);padding:1px 6px;border-radius:4px}
.champ-screen{text-align:center;padding:40px 20px}
.champ-icon{font-size:72px;margin-bottom:12px;display:block;animation:bounce2 .8s ease infinite alternate}
@keyframes bounce2{from{transform:translateY(0)}to{transform:translateY(-12px)}}

/* ── SCORE ANIMATION ── */
.mt-score span{display:inline-block}
.score-bump{animation:sBump .5s cubic-bezier(.36,.07,.19,.97) forwards}
@keyframes sBump{0%{transform:scale(1);color:#fff}35%{transform:scale(1.8);color:var(--green)}65%{transform:scale(.88);color:var(--green)}100%{transform:scale(1);color:#fff}}
.score-bump-opp{animation:sBumpR .5s cubic-bezier(.36,.07,.19,.97) forwards}
@keyframes sBumpR{0%{transform:scale(1);color:#fff}35%{transform:scale(1.8);color:var(--red)}65%{transform:scale(.88);color:var(--red)}100%{transform:scale(1);color:#fff}}

/* ── TOAST ── */
.goal-toast{position:fixed;top:40%;left:50%;transform:translate(-50%,-50%) scale(0);background:rgba(10,14,23,.93);border-radius:18px;padding:14px 32px;font-size:28px;font-weight:900;z-index:500;pointer-events:none;opacity:0;transition:transform .18s ease,opacity .18s ease;text-align:center;border:2px solid rgba(255,255,255,.1);backdrop-filter:blur(8px);white-space:nowrap}
.goal-toast.show{transform:translate(-50%,-50%) scale(1);opacity:1}
.goal-toast.type-goal{border-color:rgba(34,197,94,.5);color:var(--green)}
.goal-toast.type-save{border-color:rgba(59,130,246,.5);color:#60a5fa}
.goal-toast.type-fail{border-color:rgba(239,68,68,.5);color:var(--red)}

/* ── MODAL COMPRA ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:400;display:flex;align-items:center;justify-content:center;padding:20px}
.modal-box{background:var(--panel);border:1px solid var(--border2);border-radius:20px;padding:24px 20px;max-width:320px;width:100%;text-align:center}
.modal-badge{width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;margin:0 auto 12px}
.modal-team-name{font-size:18px;font-weight:800;color:#fff;margin-bottom:4px}
.modal-attrs{font-size:11px;color:var(--text3);margin-bottom:14px;line-height:1.8}
.modal-price{font-size:22px;font-weight:900;color:var(--gold);margin-bottom:4px}
.modal-balance{font-size:11px;color:var(--text3);margin-bottom:18px}
.modal-btns{display:flex;gap:10px}

/* ── HALL DA FAMA ── */
.hall-card{margin:0 14px 24px;background:var(--panel);border:1px solid rgba(255,215,0,.18);border-radius:14px;overflow:hidden}
.hall-hdr{background:linear-gradient(135deg,rgba(255,215,0,.08),rgba(255,153,0,.05));padding:12px 16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid rgba(255,215,0,.12)}
.hall-hdr-icon{font-size:16px}
.hall-hdr-title{font-size:12px;font-weight:800;color:var(--gold);flex:1;letter-spacing:.4px}
.hall-hdr-sub{font-size:10px;color:var(--text3)}
.hall-row{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid rgba(255,255,255,.04)}
.hall-row:last-child{border-bottom:none}
.hall-pos{font-size:15px;width:22px;text-align:center;flex-shrink:0}
.hall-name{flex:1;font-size:12px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hall-date{font-size:10px;color:var(--text3);white-space:nowrap}
.hall-empty{padding:16px;text-align:center;font-size:11px;color:var(--text3);font-style:italic}

/* ── WC 2026 HERO ── */
.wc-hero{background:linear-gradient(160deg,#0a0f1e 0%,#0d1a2e 40%,#14091a 100%);padding:18px 14px 14px;position:relative;overflow:hidden;border-bottom:1px solid rgba(255,215,0,.12)}
.wc-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 80% 50%,rgba(252,0,37,.08),transparent 60%),radial-gradient(ellipse at 20% 50%,rgba(0,80,255,.07),transparent 60%)}
.wc-host-flags{display:flex;align-items:center;gap:5px;font-size:10px;color:rgba(255,255,255,.4);font-weight:600;letter-spacing:.5px;margin-bottom:8px}
.wc-eyebrow{font-size:9px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:rgba(255,215,0,.6);margin-bottom:3px}
.wc-title{font-size:22px;font-weight:900;color:#fff;line-height:1.1;letter-spacing:-.5px}
.wc-title span{color:#FFD700}
.wc-sub{font-size:10px;color:rgba(255,255,255,.4);margin-top:3px;letter-spacing:.3px}
.wc-trophy{position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:54px;opacity:.18;filter:drop-shadow(0 0 20px rgba(255,215,0,.4))}

/* ── SHIRT SVG in card ── */
.camp-shirt{margin:3px auto 3px;display:block}

/* ── DIFFICULTY BADGE ── */
.diff-badge{display:inline-flex;align-items:center;gap:2px;font-size:7px;font-weight:800;letter-spacing:.3px;padding:2px 5px;border-radius:4px;margin-top:3px}
.diff-1{background:rgba(34,197,94,.18);color:#22c55e}
.diff-2{background:rgba(245,158,11,.18);color:#f59e0b}
.diff-3{background:rgba(249,115,22,.18);color:#f97316}
.diff-4{background:rgba(239,68,68,.18);color:#ef4444}

/* ── MISC ── */
.hidden{display:none!important}
.card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:12px}
.px{padding:0 14px}.mb12{margin-bottom:12px}.section-pad{padding:14px}
.ko-phase-hdr{padding:14px 14px 8px;font-size:16px;font-weight:800;color:var(--text)}
.ko-phase-sub{padding:0 14px 12px;font-size:11px;color:var(--text3)}
</style>
</head>
<body>

<div class="topbar">
  <a href="../games.php" class="topbar-back"><i class="bi bi-arrow-left"></i></a>
  <div class="topbar-title">🏆 <span>Copa do Mundo 2026</span></div>
  <div class="topbar-chip"><i class="bi bi-coin"></i><span id="coins-display"><?= number_format($moedas,0,',','.') ?></span></div>
  <div class="topbar-chip" style="border-color:rgba(255,215,0,.2);color:var(--gold)"><i class="bi bi-trophy-fill" style="color:var(--gold)"></i><span id="mob-conq"><?= count($conquistas_arr) ?>/16</span></div>
</div>

<div class="game-wrap">

<!-- ══ CAMPANHA ══ -->
<div class="screen active" id="sc-start">

  <!-- Hero WC 2026 -->
  <div class="wc-hero">
    <div class="wc-trophy">🏆</div>
    <div class="wc-host-flags">🇺🇸 EUA &nbsp;·&nbsp; 🇨🇦 Canadá &nbsp;·&nbsp; 🇲🇽 México</div>
    <div class="wc-eyebrow">FIFA World Cup™</div>
    <div class="wc-title">Copa do Mundo <span>2026</span></div>
    <div class="wc-sub">Pênaltis · 16 Seleções · Modo Campanha</div>
  </div>

  <div class="camp-header">
    <div>
      <div class="camp-title">Seleções</div>
      <div class="camp-progress">
        <strong id="conq-count"><?= count($conquistas_arr) ?></strong> de 16 seleções conquistadas 🏆
      </div>
    </div>
  </div>
  <div class="camp-bar-wrap">
    <div class="camp-bar-bg">
      <div class="camp-bar-fill" id="camp-bar" style="width:<?= round(count($conquistas_arr)/16*100) ?>%"></div>
    </div>
  </div>
  <div class="camp-grid" id="camp-grid"></div>

  <!-- Recompensa misteriosa -->
  <div class="mystery-card">
    <div class="mystery-q">?</div>
    <div class="mystery-title">Conquista Secreta</div>
    <div class="mystery-sub">Vença o campeonato com todas as 16 seleções<br>para descobrir o que te aguarda...</div>
    <div class="mystery-locks">🔒 🔒 🔒</div>
  </div>

  <!-- Hall da Fama -->
  <div class="hall-card">
    <div class="hall-hdr">
      <span class="hall-hdr-icon">👑</span>
      <span class="hall-hdr-title">Hall da Fama</span>
      <span class="hall-hdr-sub">Primeiros a conquistar os 16 times</span>
    </div>
    <?php if (empty($hall_fama)): ?>
      <div class="hall-empty">Nenhum campeão ainda — seja o primeiro!</div>
    <?php else: ?>
      <?php $medals = ['🥇','🥈','🥉','🏅','🏅']; ?>
      <?php foreach ($hall_fama as $i => $row): ?>
        <div class="hall-row">
          <span class="hall-pos"><?= $medals[$i] ?></span>
          <span class="hall-name"><?= htmlspecialchars($row['nome']) ?></span>
          <span class="hall-date"><?= date('d/m/Y', strtotime($row['completado_em'])) ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ══ GRUPOS ══ -->
<div class="screen" id="sc-groups">
  <div class="groups-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <div>
        <div style="font-size:16px;font-weight:800;color:#fff">Fase de Grupos</div>
        <div style="font-size:11px;color:var(--text3)" id="groups-round-label">Jogo 1 de 3</div>
      </div>
      <div id="live-indicator" style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--red);font-weight:700">
        <div class="live-dot"></div>AO VIVO
      </div>
    </div>
    <div class="match-live-feed" id="live-feed">Aguardando início dos jogos...</div>
    <div class="all-groups" id="all-groups"></div>
    <button class="btn btn-primary" id="btn-play-group" onclick="playNextGroupMatch()">
      <i class="bi bi-play-fill"></i> Jogar Jogo 1 de 3
    </button>
  </div>
</div>

<!-- ══ PARTIDA ══ -->
<div class="screen" id="sc-match">
  <div class="field-scene">
    <div class="sky"></div>
    <div class="crowd"></div>
    <div class="grass-top"></div>
    <div class="goal-container">
      <svg class="goal-svg" viewBox="0 0 320 130" xmlns="http://www.w3.org/2000/svg">
        <defs><pattern id="net" width="12" height="12" patternUnits="userSpaceOnUse"><path d="M 12 0 L 0 0 0 12" fill="none" stroke="rgba(255,255,255,.15)" stroke-width=".8"/></pattern></defs>
        <polygon points="0,130 320,130 280,90 40,90" fill="#2d7a2d" opacity=".4"/>
        <rect x="52" y="14" width="216" height="76" fill="url(#net)"/>
        <rect x="48" y="10" width="8" height="80" rx="3" fill="#ddd"/>
        <rect x="264" y="10" width="8" height="80" rx="3" fill="#ddd"/>
        <rect x="48" y="10" width="224" height="8" rx="3" fill="#ddd"/>
        <line x1="121" y1="18" x2="121" y2="90" stroke="rgba(255,255,255,.1)" stroke-width="1" stroke-dasharray="5,4"/>
        <line x1="196" y1="18" x2="196" y2="90" stroke="rgba(255,255,255,.1)" stroke-width="1" stroke-dasharray="5,4"/>
        <line x1="56" y1="54" x2="264" y2="54" stroke="rgba(255,255,255,.1)" stroke-width="1" stroke-dasharray="5,4"/>
        <rect id="zone-hl" x="0" y="0" width="0" height="0" rx="4" opacity=".7"/>
        <g id="keeper-g" style="transition:transform .22s ease">
          <ellipse cx="160" cy="74" rx="10" ry="12" fill="#ff8c00"/>
          <circle cx="160" cy="58" r="9" fill="#ffcc99"/>
          <ellipse cx="148" cy="70" rx="5" ry="4" fill="#ff8c00"/>
          <ellipse cx="172" cy="70" rx="5" ry="4" fill="#ff8c00"/>
          <rect x="153" y="83" width="6" height="14" rx="3" fill="#1a1a8f"/>
          <rect x="161" y="83" width="6" height="14" rx="3" fill="#1a1a8f"/>
        </g>
        <circle id="ball-g" cx="160" cy="200" r="8" fill="white" stroke="#333" stroke-width="1.5" opacity="0"/>
      </svg>
    </div>
    <div class="kicker-container">
      <svg id="kicker-svg" class="kicker-svg" viewBox="0 0 70 90" xmlns="http://www.w3.org/2000/svg">
        <ellipse cx="35" cy="88" rx="16" ry="3" fill="rgba(0,0,0,.3)"/>
        <circle id="kick-ball" cx="44" cy="80" r="6" fill="white" stroke="#333" stroke-width="1"/>
        <line id="kick-leg-l" x1="32" y1="62" x2="25" y2="82" stroke="#1a1a8f" stroke-width="5" stroke-linecap="round"/>
        <g id="kick-leg">
          <line id="kick-leg-r" x1="38" y1="62" x2="46" y2="80" stroke="#1a1a8f" stroke-width="5" stroke-linecap="round"/>
          <line id="kick-boot" x1="46" y1="80" x2="50" y2="82" stroke="#cc0000" stroke-width="4" stroke-linecap="round"/>
        </g>
        <rect id="kick-shirt" x="26" y="36" width="18" height="28" rx="6" fill="#cc0000"/>
        <text id="kick-number" x="35" y="54" font-size="9" fill="rgba(255,255,255,.8)" text-anchor="middle" font-weight="bold">10</text>
        <line id="kick-arm-l" x1="28" y1="42" x2="18" y2="54" stroke="#cc0000" stroke-width="5" stroke-linecap="round"/>
        <line id="kick-arm-r" x1="42" y1="42" x2="52" y2="54" stroke="#cc0000" stroke-width="5" stroke-linecap="round"/>
        <rect x="31" y="30" width="8" height="8" rx="3" fill="#ffcc99"/>
        <circle cx="35" cy="22" r="12" fill="#ffcc99"/>
        <ellipse cx="35" cy="13" rx="11" ry="5" fill="#3a2000"/>
      </svg>
    </div>
  </div>

  <div class="match-panel">
    <div class="match-top">
      <div class="mt-team">
        <div class="mt-name" id="mt-user-name">Seu Time</div>
        <div class="mt-attrs" id="mt-user-attrs"></div>
      </div>
      <div style="text-align:center">
        <div class="mt-score"><span id="mt-score-u">0</span><span style="color:var(--text3);font-size:20px"> × </span><span id="mt-score-o">0</span></div>
        <div class="mt-round" id="mt-round">Pênalti 1/5</div>
      </div>
      <div class="mt-team">
        <div class="mt-name" id="mt-opp-name">Adversário</div>
        <div class="mt-attrs" id="mt-opp-attrs"></div>
      </div>
    </div>

    <div class="dots-row">
      <div class="dots-label" id="dots-lbl-u" style="font-size:9px"></div>
      <div id="dots-user" style="display:flex;gap:3px"></div>
    </div>
    <div class="dots-row" style="padding-top:2px">
      <div class="dots-label" id="dots-lbl-o" style="font-size:9px"></div>
      <div id="dots-opp" style="display:flex;gap:3px"></div>
    </div>

    <div style="text-align:center;padding:6px 0 2px">
      <span class="phase-tag shoot" id="phase-tag">🦵 Você chuta</span>
    </div>
    <div class="status-bar s-neutral" id="status-bar">Escolha uma zona no gol</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:3px;padding:0 12px 2px;max-width:380px;margin:0 auto">
      <div style="text-align:center;font-size:9px;color:rgba(255,255,255,.35)">◀ ESQ</div>
      <div style="text-align:center;font-size:9px;color:rgba(255,255,255,.35)">▼ CEN</div>
      <div style="text-align:center;font-size:9px;color:rgba(255,255,255,.35)">DIR ▶</div>
    </div>
    <div class="zone-grid" id="zone-grid">
      <div class="zone-btn" data-zone="0" onclick="handleZone(0)"><span class="zone-icon">↖</span><span class="zone-lbl">Alto Esq</span></div>
      <div class="zone-btn" data-zone="1" onclick="handleZone(1)"><span class="zone-icon">⬆</span><span class="zone-lbl">Alto Cen</span></div>
      <div class="zone-btn" data-zone="2" onclick="handleZone(2)"><span class="zone-icon">↗</span><span class="zone-lbl">Alto Dir</span></div>
      <div class="zone-btn" data-zone="3" onclick="handleZone(3)"><span class="zone-icon">↙</span><span class="zone-lbl">Baixo Esq</span></div>
      <div class="zone-btn" data-zone="4" onclick="handleZone(4)"><span class="zone-icon">⬇</span><span class="zone-lbl">Baixo Cen</span></div>
      <div class="zone-btn" data-zone="5" onclick="handleZone(5)"><span class="zone-icon">↘</span><span class="zone-lbl">Baixo Dir</span></div>
    </div>
    <div style="padding:4px 12px 14px;text-align:center">
      <button class="btn btn-ghost hidden" id="btn-next-kick" style="padding:8px;font-size:12px">Pular ›</button>
    </div>
  </div>
</div>

<!-- ══ RESULTADO ══ -->
<div class="screen" id="sc-result">
  <div class="section-pad" style="text-align:center;padding-top:24px">
    <div class="result-big-icon" id="res-icon">🏅</div>
    <div class="result-title" id="res-title">Vitória!</div>
    <div style="font-size:13px;color:var(--text2);margin-bottom:12px" id="res-sub"></div>
    <div class="result-score-display" id="res-score"></div>
    <div style="font-size:12px;color:var(--text3);margin-bottom:20px" id="res-teams"></div>
    <button class="btn btn-primary" id="btn-res-continue" onclick="afterMatch()">Continuar ›</button>
  </div>
</div>

<!-- ══ MATA-MATA ══ -->
<div class="screen" id="sc-ko">
  <div class="ko-phase-hdr" id="ko-phase-hdr">Quartas de Final</div>
  <div class="ko-phase-sub" id="ko-phase-sub">Mata-Mata · Eliminação direta</div>
  <div class="ko-wrap" id="ko-bracket"></div>
  <div class="px mb12">
    <button class="btn btn-primary" id="btn-ko-play" onclick="playKO()">⚽ Jogar</button>
  </div>
</div>

<!-- ══ CAMPEÃO ══ -->
<div class="screen" id="sc-champ">
  <div class="champ-screen">
    <span class="champ-icon">🏆</span>
    <div style="font-size:28px;font-weight:900;color:var(--gold);margin-bottom:8px">CAMPEÃO!</div>
    <div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:6px" id="champ-name"></div>
    <div style="font-size:13px;color:var(--text3);margin-bottom:28px" id="champ-sub">Você venceu a Copa Pênaltis!</div>
    <div style="display:flex;gap:10px;max-width:280px;margin:0 auto">
      <button class="btn btn-ghost" onclick="voltarCampanha()">Campanha</button>
      <button class="btn btn-success" onclick="location.reload()">Jogar Novamente</button>
    </div>
  </div>
</div>

<!-- ══ OVERLAY ELIMINADO ══ -->
<div class="result-overlay hidden" id="ov-elim">
  <div class="result-box">
    <div class="result-big-icon">😔</div>
    <div class="result-title" style="color:var(--red)">Eliminado!</div>
    <div style="font-size:13px;color:var(--text2);margin:10px 0 18px" id="elim-msg"></div>
    <button class="btn btn-danger" onclick="voltarCampanha()">Voltar à Campanha</button>
  </div>
</div>

<!-- ══ MODAL COMPRA ══ -->
<div class="modal-overlay hidden" id="modal-compra">
  <div class="modal-box">
    <div class="modal-badge" id="modal-badge"></div>
    <div class="modal-team-name" id="modal-team-name"></div>
    <div class="modal-attrs" id="modal-attrs"></div>
    <div class="modal-price">1.000 🪙</div>
    <div class="modal-balance">Seu saldo: <strong id="modal-balance-val"></strong> moedas</div>
    <div class="modal-btns">
      <button class="btn btn-ghost" onclick="fecharModalCompra()" style="flex:1">Cancelar</button>
      <button class="btn btn-gold" onclick="confirmarCompra()" style="flex:1" id="modal-btn-comprar">Desbloquear</button>
    </div>
  </div>
</div>

<!-- ══ TOAST ══ -->
<div class="goal-toast" id="goal-toast"></div>

</div><!-- /game-wrap -->
<script>
const TIMES         = <?= json_encode(array_values($TIMES)) ?>;
const DESBLOQUEADOS = new Set(<?= json_encode($desbloqueados) ?>);
const CONQUISTAS    = new Set(<?= json_encode($conquistas_arr) ?>);
let   userMoedas    = <?= $moedas ?>;

// ── DIFICULDADE ──────────────────────────────────────────────────────────────
const DIFF_LABEL = ['','Iniciante','Médio','Difícil','Elite'];
const DIFF_STARS = ['','★','★★','★★★','★★★★'];
function diffBadge(d) {
  return `<div class="diff-badge diff-${d}">${DIFF_STARS[d]} ${DIFF_LABEL[d]}</div>`;
}

// ── SHIRT ICON (mini SVG para os cards) ──────────────────────────────────────
function shirtIcon(c1, c2) {
  return `<svg class="camp-shirt" width="34" height="32" viewBox="0 0 34 32" xmlns="http://www.w3.org/2000/svg">
    <polygon points="7,4 0,10 4,14 4,30 30,30 30,14 34,10 27,4 22,4 22,7 12,7 12,4" fill="${c1}" stroke="${c2}" stroke-width="1.2"/>
    <line x1="12" y1="4" x2="12" y2="7" stroke="${c2}" stroke-width="1"/>
    <line x1="22" y1="4" x2="22" y2="7" stroke="${c2}" stroke-width="1"/>
    <text x="17" y="22" font-size="7" fill="${c2}" text-anchor="middle" font-weight="800" opacity=".85">10</text>
  </svg>`;
}

// ── TROCAR COR DO KICKER ─────────────────────────────────────────────────────
function applyKickerColors(shirt, shirt2) {
  const s = shirt  || '#cc0000';
  const p = shirt2 || '#1a1a8f';
  document.getElementById('kick-shirt').setAttribute('fill', s);
  document.getElementById('kick-arm-l').setAttribute('stroke', s);
  document.getElementById('kick-arm-r').setAttribute('stroke', s);
  document.getElementById('kick-boot').setAttribute('stroke', s);
  document.getElementById('kick-leg-l').setAttribute('stroke', p);
  document.getElementById('kick-leg-r').setAttribute('stroke', p);
}

// ── TIMING ───────────────────────────────────────────────────────────────────
const RESULT_DELAY = 600;
const AUTO_NEXT    = 2000;

// ── SETOR ────────────────────────────────────────────────────────────────────
function sector(z) { return z % 3; }

// Goleiro adversário defende: stat do GK inimigo vs stat de chute do user
function keeperDive(shootZone, shooterShot, gkDef, koBoost = 0) {
  const base      = 0.08 + (gkDef - 5) * 0.08;          // def6→16% def10→48%
  const reduction = (shooterShot - 5) * 0.03;            // shot10→15% menos
  const readChance = Math.min(0.58, Math.max(0.06, base - reduction + koBoost * 0.05));
  const sec = Math.random() < readChance ? sector(shootZone) : Math.floor(Math.random() * 3);
  return sec + (Math.random() < 0.5 ? 0 : 3);
}

// Adversário chuta: stat de chute determina preferência por cantos
function aiShootZone(playerShot, koBoost = 0) {
  const cornerBias = Math.min(0.85, 0.28 + (playerShot - 5) * 0.09 + koBoost * 0.05);
  const side = Math.random() < cornerBias ? (Math.random() < 0.5 ? 0 : 2) : 1;
  return side + (Math.random() < 0.5 ? 0 : 3);
}

// ── ESTADO ───────────────────────────────────────────────────────────────────
let state = null;
let _pendingPurchaseSlug = null;

// ── GOAL GEOMETRY ─────────────────────────────────────────────────────────────
const ZONE_RECTS = [
  [56,18,65,36],[121,18,75,36],[196,18,68,36],
  [56,54,65,36],[121,54,75,36],[196,54,68,36],
];
function zoneCX(z){ const r=ZONE_RECTS[z]; return r[0]+r[2]/2; }
function zoneCY(z){ const r=ZONE_RECTS[z]; return r[1]+r[3]/2; }
const KEEPER_X = {0:-80,1:-15,2:55,3:-80,4:-15,5:55};

// ── CAMPANHA ──────────────────────────────────────────────────────────────────
function renderCampaign() {
  const grid = document.getElementById('camp-grid');
  grid.innerHTML = TIMES.map(t => {
    const locked      = !DESBLOQUEADOS.has(t.slug);
    const conquered   = CONQUISTAS.has(t.slug);
    const badgeStyle  = `background:${t.color}22;color:${t.color};border:1px solid ${t.color}55`;
    const borderColor = conquered ? 'var(--gold)' : locked ? 'var(--border)' : t.color + '55';
    const shirtSvg    = shirtIcon(t.shirt || t.color, t.shirt2 || '#fff');

    if (locked) {
      return `<div class="camp-card locked" style="border-color:${borderColor}" onclick="abrirModalCompra('${t.slug}')">
        ${shirtSvg}
        <div class="camp-name">${t.name}</div>
        ${diffBadge(t.diff)}
        <div class="camp-lock" style="margin-top:3px">🔒</div>
        <div class="camp-price">1.000 🪙</div>
      </div>`;
    }
    if (conquered) {
      return `<div class="camp-card conquered" style="border-color:var(--gold)" onclick="startGame(TIMES.find(x=>x.slug==='${t.slug}'))">
        <div class="camp-trophy">🏆</div>
        ${shirtSvg}
        <div class="camp-name">${t.name}</div>
        ${diffBadge(t.diff)}
        <div class="camp-conquered-lbl" style="margin-top:2px">Campeão!</div>
      </div>`;
    }
    return `<div class="camp-card unlocked" style="border-color:${borderColor}" onclick="startGame(TIMES.find(x=>x.slug==='${t.slug}'))">
      ${shirtSvg}
      <div class="camp-name">${t.name}</div>
      ${diffBadge(t.diff)}
      <div class="camp-play-btn" style="margin-top:3px">▶ JOGAR</div>
    </div>`;
  }).join('');

  const total = CONQUISTAS.size;
  document.getElementById('conq-count').textContent = total;
  document.getElementById('camp-bar').style.width = (total / 16 * 100) + '%';
}

// ── MODAL COMPRA ──────────────────────────────────────────────────────────────
function abrirModalCompra(slug) {
  const t = TIMES.find(x => x.slug === slug);
  if (!t) return;
  _pendingPurchaseSlug = slug;
  document.getElementById('modal-badge').textContent    = t.badge;
  document.getElementById('modal-badge').style.cssText  = `background:${t.color}22;color:${t.color};border:1px solid ${t.color}55;width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;margin:0 auto 12px`;
  document.getElementById('modal-team-name').textContent = t.name;
  document.getElementById('modal-attrs').innerHTML = `🧤 ${t.gk} &nbsp;·&nbsp; ⚽ ${t.player}`;
  document.getElementById('modal-balance-val').textContent = userMoedas.toLocaleString('pt-BR');
  const btn = document.getElementById('modal-btn-comprar');
  btn.disabled = userMoedas < 1000;
  btn.textContent = userMoedas < 1000 ? 'Moedas insuficientes' : 'Desbloquear';
  document.getElementById('modal-compra').classList.remove('hidden');
}
function fecharModalCompra() {
  document.getElementById('modal-compra').classList.add('hidden');
  _pendingPurchaseSlug = null;
}
async function confirmarCompra() {
  if (!_pendingPurchaseSlug) return;
  const slug = _pendingPurchaseSlug;
  const btn  = document.getElementById('modal-btn-comprar');
  btn.disabled = true; btn.textContent = 'Processando...';
  try {
    const r = await fetch(window.location.href, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'comprar_time', slug})
    });
    const d = await r.json();
    if (d.ok) {
      DESBLOQUEADOS.add(slug);
      if (d.moedas !== undefined) {
        userMoedas = d.moedas;
        updateCoinDisplay(userMoedas);
      }
      fecharModalCompra();
      renderCampaign();
    } else {
      alert(d.msg || 'Erro ao comprar');
      btn.disabled = false; btn.textContent = 'Desbloquear';
    }
  } catch(e) { btn.disabled = false; btn.textContent = 'Desbloquear'; }
}

// ── INÍCIO / CAMPANHA ─────────────────────────────────────────────────────────
function voltarCampanha() {
  document.getElementById('ov-elim').classList.add('hidden');
  renderCampaign();
  showScreen('sc-start');
}

function startGame(userTeam) {
  if (!userTeam) return;
  state = {
    userTeam,
    phase: 'groups',
    groups: buildGroups(userTeam),
    gmIdx: 0,
    gmList: [],
    cur: null,
    koRound: 0,
    koBracket: [],
  };
  state.gmList = buildUserMatches(state.groups);
  showScreen('sc-groups');
  renderGroups();
  simulateOtherGroupsLive();
}

// ── GRUPOS ────────────────────────────────────────────────────────────────────
function buildGroups(userTeam) {
  const pool = TIMES.filter(t => t.slug !== userTeam.slug);
  shuffle(pool);
  const picked = [userTeam, ...pool.slice(0,15)];
  const letters = ['A','B','C','D'];
  return letters.map((l,i) => ({
    name: 'Grupo '+l,
    teams: picked.slice(i*4, i*4+4).map(t => ({...t, pts:0, gs:0, gc:0, played:0}))
  }));
}
function buildUserMatches(groups) {
  return groups[0].teams.filter(t => t.slug !== state.userTeam.slug).map(opp => ({opp, done:false}));
}

let liveSimTimer = null;
function simulateOtherGroupsLive() {
  if (liveSimTimer) clearInterval(liveSimTimer);
  const confrontos = [];
  for (let gi=1; gi<state.groups.length; gi++) {
    const g = state.groups[gi];
    for (let a=0; a<g.teams.length; a++)
      for (let b=a+1; b<g.teams.length; b++)
        confrontos.push({gi,a,b});
  }
  shuffle(confrontos);
  let idx=0;
  liveSimTimer = setInterval(() => {
    if (idx>=confrontos.length || state.gmIdx>=3) { clearInterval(liveSimTimer); return; }
    const {gi,a,b} = confrontos[idx++];
    const g=state.groups[gi], ta=g.teams[a], tb=g.teams[b];
    const ga=Math.floor(Math.random()*4), gb=Math.floor(Math.random()*4);
    ta.gs+=ga; ta.gc+=gb; ta.played++;
    tb.gs+=gb; tb.gc+=ga; tb.played++;
    if (ga>gb) ta.pts+=3; else if (ga===gb){ta.pts+=1;tb.pts+=1;} else tb.pts+=3;
    const feed=document.getElementById('live-feed');
    if (feed) feed.innerHTML=`<span class="live-result">⚽ ${g.name}: <b>${ta.name}</b> ${ga} × ${gb} <b>${tb.name}</b></span>`;
    renderGroups();
  }, 1800);
}

function renderGroups() {
  const wrap=document.getElementById('all-groups'); if (!wrap) return;
  wrap.innerHTML = state.groups.map((g,gi) => {
    const sorted=[...g.teams].sort((a,b)=>(b.pts-a.pts)||((b.gs-b.gc)-(a.gs-a.gc)));
    const done=gi>0?sorted[0].played>=3:state.gmIdx>=3;
    const header=`<div style="display:flex;justify-content:space-between;align-items:center;padding:0 0 4px;margin-bottom:4px;border-bottom:1px solid rgba(255,255,255,.08)">
      <span style="font-size:9px;color:var(--text3);font-weight:700">TIME</span>
      <div style="display:flex;gap:14px">
        <span style="font-size:9px;color:var(--text3);min-width:18px;text-align:center">J</span>
        <span style="font-size:9px;color:var(--text3);min-width:28px;text-align:center">SG</span>
        <span style="font-size:9px;color:var(--text3);min-width:22px;text-align:right">PTS</span>
      </div></div>`;
    return `<div class="group-card ${gi===0?'user-group':''}">
      <div class="group-hdr">
        <span>${g.name}${gi===0?' <span style="color:var(--amber);font-size:9px">▶ SEU GRUPO</span>':''}</span>
        ${!done?'<div class="live-dot"></div>':'<span style="font-size:9px;color:var(--text3)">✓ Encerrado</span>'}
      </div>${header}
      ${sorted.map((t,i)=>{
        const isUser=t.slug===state.userTeam.slug;
        const saldo=t.gs-t.gc;
        const tag=done?(i<2?'<span class="classif q">Q</span>':'<span class="classif e">E</span>'):'';
        return `<div class="g-team">
          <div class="g-pos" style="color:${i<2&&done?'var(--green)':'var(--text3)'}">${i+1}º</div>
          <div class="g-dot" style="background:${t.color}"></div>
          <div class="g-name ${isUser?'user-team':''}">${t.name}${tag}</div>
          <div style="display:flex;gap:14px;align-items:center">
            <div class="g-pld">${t.played}</div>
            <div class="g-saldo" style="color:${saldo>=0?'var(--green)':'var(--red)'}">${saldo>0?'+':''}${saldo}</div>
            <div class="g-pts">${t.pts}</div>
          </div></div>`;
      }).join('')}
    </div>`;
  }).join('');
  const btn=document.getElementById('btn-play-group'); if (!btn) return;
  if (state.gmIdx>=3) { btn.textContent='📋 Classificação Final & Avançar'; btn.onclick=advanceFromGroups; }
  else { btn.textContent=`⚽ Jogar Jogo ${state.gmIdx+1} de 3`; btn.onclick=playNextGroupMatch; }
  document.getElementById('groups-round-label').textContent=state.gmIdx>=3?'Fase de grupos concluída':`Jogo ${state.gmIdx+1} de 3`;
}

function playNextGroupMatch() {
  if (state.gmIdx>=3) { advanceFromGroups(); return; }
  startMatch(state.gmList[state.gmIdx].opp, false);
}

// ── PARTIDA ───────────────────────────────────────────────────────────────────
function startMatch(opp, isKO, koBoost=0) {
  if (liveSimTimer) clearInterval(liveSimTimer);
  state.cur = { opp, isKO, koBoost, uGoals:0, oGoals:0, kickIdx:0, locked:false, sd:false, sdPhase:0, sdUserScored:null };
  resetZoneBtns();
  updateMatchUI();
  initDots();
  showScreen('sc-match');
  prepKick();
}

function updateMatchUI() {
  const {cur,userTeam}=state;
  document.getElementById('mt-user-name').textContent = userTeam.name;
  document.getElementById('mt-opp-name').textContent  = cur.opp.name;
  document.getElementById('mt-user-attrs').innerHTML  = `🧤 ${userTeam.gk} · ⚽ ${userTeam.player}`;
  document.getElementById('mt-opp-attrs').innerHTML   = `🧤 ${cur.opp.gk} · ⚽ ${cur.opp.player}`;
  document.getElementById('mt-score-u').textContent   = 0;
  document.getElementById('mt-score-o').textContent   = 0;
  document.getElementById('dots-lbl-u').textContent   = userTeam.badge;
  document.getElementById('dots-lbl-o').textContent   = cur.opp.badge;
  applyKickerColors(userTeam.shirt || userTeam.color, userTeam.shirt2 || userTeam.dark);
}

function initDots() {
  ['dots-user','dots-opp'].forEach(id => {
    const el=document.getElementById(id); el.innerHTML='';
    for (let i=0;i<5;i++) {
      const d=document.createElement('div'); d.className='dot pending'; d.id=id+'-'+i; el.appendChild(d);
    }
  });
}

function prepKick() {
  const m=state.cur; if (m.locked) return;
  const userShooting=m.kickIdx%2===0;
  const round=Math.floor(m.kickIdx/2)+1;
  document.getElementById('mt-round').textContent=`Pênalti ${round}/5`;
  const tag=document.getElementById('phase-tag');
  if (m.sd) { tag.className='phase-tag sd'; tag.textContent=m.sdPhase===0?'💀 Morte Súbita — Chute!':'💀 Morte Súbita — Defenda!'; }
  else if (userShooting) { tag.className='phase-tag shoot'; tag.textContent=`🦵 Você chuta (${round}/5)`; }
  else { tag.className='phase-tag defend'; tag.textContent=`🧤 Você defende (${round}/5)`; }
  setStatus(m.sd?'Clique na zona!':userShooting?'Escolha onde chutar':'Escolha a zona para defender','neutral');
  resetZoneBtns();
  document.getElementById('btn-next-kick').classList.add('hidden');
  moveKeeper(4);
}

// ── TOAST ────────────────────────────────────────────────────────────────────
let _toastT=null;
function showToast(text,type) {
  const el=document.getElementById('goal-toast'); if (!el) return;
  if (_toastT) clearTimeout(_toastT);
  el.textContent=text; el.className=`goal-toast show ${type}`;
  _toastT=setTimeout(()=>el.classList.remove('show'),1100);
}

// ── PLACAR ───────────────────────────────────────────────────────────────────
function addGoal(side) {
  const cur=state.cur;
  if (side==='user') cur.uGoals++; else cur.oGoals++;
  const elId=side==='user'?'mt-score-u':'mt-score-o';
  const cls =side==='user'?'score-bump':'score-bump-opp';
  const el  =document.getElementById(elId);
  el.textContent=side==='user'?cur.uGoals:cur.oGoals;
  el.classList.remove('score-bump','score-bump-opp'); void el.offsetWidth; el.classList.add(cls);
  setTimeout(()=>el.classList.remove(cls),500);
}
function updateDot(side,kickRound,result) {
  const el=document.getElementById(`dots-${side}-${kickRound}`); if (!el) return;
  el.className=result==='goal'?'dot dot-scored':'dot dot-missed';
  el.textContent=result==='goal'?'⚽':'✕';
}
function shouldEndEarly() {
  const m=state.cur; if (m.sd) return false;
  const userDone=Math.ceil(m.kickIdx/2), oppDone=Math.floor(m.kickIdx/2);
  const maxUser=m.uGoals+(5-userDone), maxOpp=m.oGoals+(5-oppDone);
  return maxUser<m.oGoals || maxOpp<m.uGoals;
}

// ── CLICK NA ZONA ─────────────────────────────────────────────────────────────
function handleZone(zone) {
  const m=state.cur; if (m.locked) return; m.locked=true;
  if (m.sd) { handleSD(zone); return; }
  const userShooting=m.kickIdx%2===0;
  const kickRound=Math.floor(m.kickIdx/2);
  let result, keepZone;

  if (userShooting) {
    keepZone=keeperDive(zone, state.userTeam.player_shot, m.opp.gk_def, m.koBoost);
    result=sector(zone)===sector(keepZone)?'save':'goal';
    animateShoot(zone,keepZone,result,true);
  } else {
    const oppZone=aiShootZone(m.opp.player_shot, m.koBoost);
    const sectorMatch=sector(zone)===sector(oppZone);
    // Bônus do goleiro: chance de defesa milagrosa mesmo no setor errado
    const gkBonus=Math.max(0,(state.userTeam.gk_def-5)*0.025);
    const saved=sectorMatch||(Math.random()<gkBonus);
    result=saved?'save':'goal';
    keepZone=saved?oppZone:zone;
    animateShoot(oppZone,keepZone,result,false);
  }

  m.kickIdx++;

  setTimeout(()=>{
    updateDot(userShooting?'user':'opp', kickRound, result);
    if (userShooting) {
      if (result==='goal') { addGoal('user'); showToast('⚽ GOL!','type-goal'); setStatus('⚽ GOL! Você marcou!','ok'); }
      else { showToast('🧤 Defendido','type-save'); setStatus('🧤 Defendido! O goleiro foi no lado certo.','fail'); }
    } else {
      if (result==='goal') { addGoal('opp'); showToast('😬 Gol deles!','type-fail'); setStatus('😬 Gol do adversário!','fail'); }
      else { showToast('🧤 Defendeu!','type-goal'); setStatus('🧤 Você defendeu!','ok'); }
    }
    const earlyEnd=shouldEndEarly();
    if (m.kickIdx>=10 || earlyEnd) {
      if (earlyEnd && m.kickIdx<10) {
        const lead=m.uGoals>m.oGoals;
        setTimeout(()=>setStatus(
          lead ? `✅ ${m.opp.name} não consegue mais virar — fim de jogo!`
               : `❌ Você não consegue mais virar — fim de jogo!`,
          lead?'ok':'fail'
        ), 400);
      }
      setTimeout(finishMatch, 1400);
    } else {
      const skipBtn=document.getElementById('btn-next-kick');
      skipBtn.classList.remove('hidden');
      const autoTimer=setTimeout(()=>{ skipBtn.classList.add('hidden'); nextKick(); }, AUTO_NEXT);
      skipBtn.onclick=()=>{ clearTimeout(autoTimer); skipBtn.classList.add('hidden'); nextKick(); };
    }
  }, RESULT_DELAY);
}

// ── ANIMAÇÕES ────────────────────────────────────────────────────────────────
function animateShoot(ballZone,keeperZone,result) {
  const ksvg=document.getElementById('kicker-svg');
  ksvg.classList.add('kick-anim'); setTimeout(()=>ksvg.classList.remove('kick-anim'),400);
  const kickBall=document.getElementById('kick-ball'); if (kickBall) kickBall.setAttribute('opacity','0');
  setTimeout(()=>moveKeeper(keeperZone),150);
  highlightZone(ballZone,result);
  setTimeout(()=>{ const btn=document.querySelector(`.zone-btn[data-zone="${ballZone}"]`); if (btn) btn.classList.add(result==='goal'?'hit-goal':'hit-save'); },300);
  animBallSVG(ballZone,result);
}
function animBallSVG(zone,result) {
  const ball=document.getElementById('ball-g'); if (!ball) return;
  const tx=zoneCX(zone),ty=zoneCY(zone);
  ball.setAttribute('cx',160); ball.setAttribute('cy',118); ball.setAttribute('r',11);
  ball.setAttribute('opacity','1'); ball.setAttribute('fill','white'); ball.setAttribute('stroke','#333');
  let progress=0;
  const anim=setInterval(()=>{
    progress+=0.045; if (progress>=1){progress=1;clearInterval(anim);}
    const ease=progress<0.5?2*progress*progress:-1+(4-2*progress)*progress;
    ball.setAttribute('cx',160+(tx-160)*ease); ball.setAttribute('cy',118+(ty-118)*ease);
    ball.setAttribute('r',11-ease*5);
    if (progress>=1) ball.setAttribute('fill',result==='goal'?'#22c55e':'#ef4444');
  },16);
  setTimeout(()=>{
    ball.setAttribute('opacity','0'); ball.setAttribute('cx',160); ball.setAttribute('cy',200); ball.setAttribute('fill','white');
    const kb=document.getElementById('kick-ball'); if (kb) kb.setAttribute('opacity','1');
  },1400);
}
function moveKeeper(zone) {
  const kg=document.getElementById('keeper-g'); if (!kg) return;
  kg.style.transform=`translate(${KEEPER_X[zone]??0}px,${zone<=2?-18:0}px)`;
}
function highlightZone(zone,result) {
  const hl=document.getElementById('zone-hl'); if (!hl) return;
  const r=ZONE_RECTS[zone];
  hl.setAttribute('x',r[0]); hl.setAttribute('y',r[1]); hl.setAttribute('width',r[2]); hl.setAttribute('height',r[3]);
  hl.setAttribute('fill',result==='goal'?'rgba(34,197,94,.4)':'rgba(239,68,68,.35)');
  setTimeout(()=>hl.setAttribute('width','0'),1200);
}
function resetZoneBtns() { document.querySelectorAll('.zone-btn').forEach(b=>b.classList.remove('hit-goal','hit-save')); }
function setStatus(msg,cls) { const el=document.getElementById('status-bar'); el.textContent=msg; el.className='status-bar s-'+cls; }
function nextKick() {
  state.cur.locked=false;
  document.getElementById('btn-next-kick').classList.add('hidden');
  resetZoneBtns();
  const hl=document.getElementById('zone-hl'); if (hl) hl.setAttribute('width','0');
  moveKeeper(4);
  const kb=document.getElementById('kick-ball'); if (kb) kb.setAttribute('opacity','1');
  prepKick();
}

// ── FINALIZAR PARTIDA ─────────────────────────────────────────────────────────
function finishMatch() {
  const m=state.cur; const win=m.uGoals>m.oGoals, draw=m.uGoals===m.oGoals;
  if (m.isKO&&draw){startSD();return;}
  if (!m.isKO){updateGroupStandings(m.opp,m.uGoals,m.oGoals);state.gmIdx++;}
  showResult(win,draw);
}

// ── MORTE SÚBITA ──────────────────────────────────────────────────────────────
function startSD() {
  const m=state.cur; m.sd=true;m.sdPhase=0;m.sdUserScored=null;m.locked=false;
  initDots();resetZoneBtns();moveKeeper(4);
  document.getElementById('mt-round').textContent='Morte Súbita!'; prepKick();
}
function handleSD(zone) {
  const m=state.cur;
  if (m.sdPhase===0) {
    const keepZone=keeperDive(zone,state.userTeam.player_shot,m.opp.gk_def,m.koBoost);
    const saved=sector(zone)===sector(keepZone); m.sdUserScored=!saved;
    animateShoot(zone,keepZone,saved?'save':'goal',true);
    setTimeout(()=>{
      if (m.sdUserScored){showToast('⚽ GOL!','type-goal');setStatus('⚽ Gol! Agora defenda para vencer!','ok');}
      else{showToast('🧤 Defendido','type-save');setStatus('🧤 Defendido! Defenda para não perder!','fail');}
    },RESULT_DELAY);
    m.sdPhase=1;
    setTimeout(()=>{resetZoneBtns();moveKeeper(4);prepKick();m.locked=false;},RESULT_DELAY+900);
  } else {
    const oppZone=aiShootZone(m.opp.player_shot,m.koBoost);
    const sectorMatch=sector(zone)===sector(oppZone);
    const gkBonus=Math.max(0,(state.userTeam.gk_def-5)*0.025);
    const saved=sectorMatch||(Math.random()<gkBonus);
    const scored=!saved;
    animateShoot(oppZone,saved?oppZone:zone,scored?'goal':'save',false);
    const userWins=m.sdUserScored&&!scored, userLoses=!m.sdUserScored&&scored;
    setTimeout(()=>{
      if (!scored){showToast('🧤 Defendeu!','type-goal');setStatus('🧤 Você defendeu!','ok');}
      else{showToast('😬 Gol deles!','type-fail');setStatus('😬 Gol do adversário!','fail');}
      setTimeout(()=>{
        if (userWins){addGoal('user');showResult(true,false);}
        else if (userLoses){addGoal('opp');showResult(false,false);}
        else{m.sdPhase=0;m.sdUserScored=null;m.locked=false;resetZoneBtns();moveKeeper(4);prepKick();setStatus('Nova rodada!','neutral');}
      },500);
    },RESULT_DELAY);
  }
}

// ── STANDINGS ─────────────────────────────────────────────────────────────────
function updateGroupStandings(opp,uG,oG) {
  const g=state.groups[0];
  const u=g.teams.find(t=>t.slug===state.userTeam.slug);
  const o=g.teams.find(t=>t.slug===opp.slug);
  u.gs+=uG;u.gc+=oG;u.played++;o.gs+=oG;o.gc+=uG;o.played++;
  if (uG>oG)u.pts+=3;else if(uG===oG){u.pts+=1;o.pts+=1;}else o.pts+=3;
  const rest=g.teams.filter(t=>t.slug!==state.userTeam.slug&&t.slug!==opp.slug);
  if (rest.length>=2){
    const ga=Math.floor(Math.random()*4),gb=Math.floor(Math.random()*4);
    rest[0].gs+=ga;rest[0].gc+=gb;rest[0].played++;rest[1].gs+=gb;rest[1].gc+=ga;rest[1].played++;
    if(ga>gb)rest[0].pts+=3;else if(ga===gb){rest[0].pts+=1;rest[1].pts+=1;}else rest[1].pts+=3;
  }
}

// ── RESULTADO ─────────────────────────────────────────────────────────────────
function showResult(win,draw) {
  const m=state.cur;
  document.getElementById('res-icon').textContent=win?'🏅':draw?'🤝':'😓';
  document.getElementById('res-title').textContent=win?'Vitória!':draw?'Empate!':(m.isKO?'Eliminado!':'Derrota!');
  document.getElementById('res-title').style.color=win?'var(--green)':draw?'var(--amber)':(m.isKO?'var(--red)':'var(--amber)');
  document.getElementById('res-score').textContent=`${m.uGoals} × ${m.oGoals}`;
  document.getElementById('res-teams').textContent=`${state.userTeam.name} vs ${m.opp.name}`;
  document.getElementById('res-sub').textContent=win?(m.isKO?`${state.userTeam.name} avança!`:'Vitória na fase de grupos!'):draw?'Empate — 1 ponto cada.':(m.isKO?'Você foi eliminado.':'Derrota, mas ainda há jogos!');
  const btn=document.getElementById('btn-res-continue');
  if (m.isKO&&!win){btn.textContent='Voltar à Campanha';btn.onclick=voltarCampanha;}
  else{btn.textContent='Continuar ›';btn.onclick=afterMatch;}
  showScreen('sc-result');
}
function afterMatch() {
  if (state.cur.isKO){advanceKO();return;}
  showScreen('sc-groups');renderGroups();simulateOtherGroupsLive();
}

// ── AVANÇAR DOS GRUPOS ────────────────────────────────────────────────────────
function advanceFromGroups() {
  if (liveSimTimer) clearInterval(liveSimTimer);
  const g=state.groups[0];
  const sorted=[...g.teams].sort((a,b)=>(b.pts-a.pts)||((b.gs-b.gc)-(a.gs-a.gc)));
  const userPos=sorted.findIndex(t=>t.slug===state.userTeam.slug);
  if (userPos>=2){
    document.getElementById('elim-msg').textContent=`${state.userTeam.name} terminou em ${userPos+1}º no Grupo A e não avançou.`;
    document.getElementById('ov-elim').classList.remove('hidden'); return;
  }
  state.koBracket=[];
  state.groups.forEach(grp=>{
    const s=[...grp.teams].sort((a,b)=>(b.pts-a.pts)||((b.gs-b.gc)-(a.gs-a.gc)));
    state.koBracket.push(s[0],s[1]);
  });
  state.koRound=0; state.phase='knockout'; showKO();
}

// ── MATA-MATA ─────────────────────────────────────────────────────────────────
const KO_NAMES=['Oitavas de Final','Quartas de Final','Semifinal','Final'];
function showKO() {
  const r=state.koRound;
  document.getElementById('ko-phase-hdr').textContent=KO_NAMES[r]??'Final';
  document.getElementById('ko-phase-sub').textContent='Mata-Mata · Eliminação direta · Empate = Morte Súbita';
  const bracket=state.koBracket;
  const userIdx=bracket.findIndex(t=>t&&t.slug===state.userTeam.slug);
  const pairIdx=userIdx%2===0?userIdx+1:userIdx-1;
  const userOpp=bracket[pairIdx];
  let html='';
  for (let i=0;i<bracket.length;i+=2){
    const t1=bracket[i],t2=bracket[i+1]; if (!t1||!t2) continue;
    const isUM=t1.slug===state.userTeam.slug||t2.slug===state.userTeam.slug;
    html+=`<div class="ko-match ${isUM?'user-match':''}">
      ${isUM?`<div style="font-size:10px;color:var(--amber);padding:6px 12px 0;font-weight:700">⭐ SEU JOGO</div>`:''}
      <div class="ko-team-row">
        <div class="ko-badge" style="background:${t1.color}22;color:${t1.color}">${t1.badge}</div>
        <div class="ko-name">${t1.name} ${t1.slug===state.userTeam.slug?'<span class="user-lbl">VOCÊ</span>':''}</div>
      </div>
      <div class="ko-team-row">
        <div class="ko-badge" style="background:${t2.color}22;color:${t2.color}">${t2.badge}</div>
        <div class="ko-name">${t2.name} ${t2.slug===state.userTeam.slug?'<span class="user-lbl">VOCÊ</span>':''}</div>
      </div></div>`;
  }
  document.getElementById('ko-bracket').innerHTML=html;
  document.getElementById('btn-ko-play').textContent=`⚽ Jogar vs ${userOpp?.name??'?'}`;
  showScreen('sc-ko');
}
function playKO() {
  const bracket=state.koBracket;
  const userIdx=bracket.findIndex(t=>t&&t.slug===state.userTeam.slug);
  const pairIdx=userIdx%2===0?userIdx+1:userIdx-1;
  startMatch(bracket[pairIdx],true,state.koRound+1);
}
function advanceKO() {
  const m=state.cur; if (m.uGoals<=m.oGoals){voltarCampanha();return;}
  const bracket=state.koBracket, winners=[];
  for (let i=0;i<bracket.length;i+=2){
    const t1=bracket[i],t2=bracket[i+1];
    if (!t1||!t2){winners.push(t1??t2);continue;}
    if (t1.slug===state.userTeam.slug){winners.push(state.userTeam);continue;}
    if (t2.slug===state.userTeam.slug){winners.push(state.userTeam);continue;}
    // AI vs AI: usa stats para determinar vencedor
    const s1=t1.gk_def+t1.player_shot, s2=t2.gk_def+t2.player_shot;
    winners.push(Math.random()<s1/(s1+s2)?t1:t2);
  }
  state.koBracket=winners; state.koRound++;
  if (winners.length===1) {
    registrarConquista(state.userTeam.slug);
    return;
  }
  showKO();
}

// ── CONQUISTA ─────────────────────────────────────────────────────────────────
async function registrarConquista(slug) {
  CONQUISTAS.add(slug);
  updateConqDisplay();
  document.getElementById('champ-name').textContent = state.userTeam.name;
  const totalConq = CONQUISTAS.size;
  document.getElementById('champ-sub').textContent =
    totalConq >= 16
      ? '🏆 Você conquistou todas as 16 seleções! Acesse a campanha para ver sua recompensa.'
      : `Você venceu a Copa Pênaltis! (${totalConq}/16 seleções)`;
  showScreen('sc-champ');
  try {
    const r = await fetch(window.location.href, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'registrar_conquista', slug})
    });
    const d = await r.json();
    if (d.primeira_vez && d.moedas !== null) {
      userMoedas = d.moedas;
      updateCoinDisplay(userMoedas);
      const sub = document.getElementById('champ-sub');
      if (sub) sub.innerHTML += '<br><span style="color:var(--amber);font-weight:700">+500 🪙 moedas ganhas!</span>';
    }
  } catch(e) {}
}

// ── UTILS ─────────────────────────────────────────────────────────────────────
function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s=>s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  window.scrollTo(0,0);
}
function shuffle(arr) {
  for (let i=arr.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[arr[i],arr[j]]=[arr[j],arr[i]];}
  return arr;
}
function updateCoinDisplay(val) {
  document.getElementById('coins-display').textContent = val.toLocaleString('pt-BR');
}
function updateConqDisplay() {
  const total = CONQUISTAS.size;
  document.getElementById('conq-count').textContent = total;
  document.getElementById('camp-bar').style.width = (total/16*100)+'%';
  const mob = document.getElementById('mob-conq');
  if (mob) mob.textContent = total+'/16';
}

// ── INIT ──────────────────────────────────────────────────────────────────────
renderCampaign();
</script>
</body>
</html>
