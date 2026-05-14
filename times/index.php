<?php
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';

$pdo = db();

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') { http_response_code(404); echo "Time não encontrado."; exit; }

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE public_slug = ? AND public_enabled = 1 LIMIT 1');
$stmtTeam->execute([$slug]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
if (!$team) { http_response_code(404); echo "Time não encontrado ou página desativada."; exit; }

$moduleKeys = ['roster','lineup','titles','picks','ranking','ai_avgs','trades'];
$modules = [];
if (!empty($team['public_modules'])) {
    $dec = json_decode((string)$team['public_modules'], true);
    if (is_array($dec)) $modules = array_values(array_filter($dec, fn($k) => is_string($k) && in_array($k, $moduleKeys)));
}
if (!$modules) $modules = $moduleKeys;

$primary   = $team['public_primary_color']   ?: '#fc0025';
$secondary = $team['public_secondary_color'] ?: '#ff2a44';
$teamId    = (int)$team['id'];
$league    = (string)($team['league'] ?? '');
$teamCity  = (string)($team['city'] ?? '');
$teamName  = (string)($team['name'] ?? '');
$teamLogo  = $team['photo_url'] ?: '/img/default-team.png';
$teamDisplayName = trim($teamCity . ' ' . $teamName);

// Players
$players = $starters = [];
if (in_array('roster',$modules)||in_array('lineup',$modules)||in_array('ai_avgs',$modules)) {
    $s = $pdo->prepare('SELECT * FROM players WHERE team_id = ? ORDER BY ovr DESC, age ASC, name ASC');
    $s->execute([$teamId]);
    $players  = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $starters = array_values(array_filter($players, fn($p) => ($p['role'] ?? '') === 'Titular'));
}

// Titles
$championships = 0;
if (in_array('titles',$modules)) {
    try { $s=$pdo->prepare('SELECT COUNT(*) FROM season_history WHERE champion_team_id=?'); $s->execute([$teamId]); $championships=(int)$s->fetchColumn(); } catch(Exception $e){}
}

// Picks with team names
$picks = [];
if (in_array('picks',$modules)) {
    try {
        $s = $pdo->prepare('SELECT p.season_year,p.round,p.notes,p.original_team_id,COALESCE(t.city,\'\') AS orig_city,COALESCE(t.name,\'\') AS orig_name FROM picks p LEFT JOIN teams t ON t.id=p.original_team_id WHERE p.team_id=? ORDER BY p.season_year ASC,p.round ASC,p.id ASC');
        $s->execute([$teamId]);
        $picks = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Exception $e) {
        $s = $pdo->prepare('SELECT season_year,round,notes,original_team_id FROM picks WHERE team_id=? ORDER BY season_year ASC,round ASC');
        $s->execute([$teamId]);
        $picks = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
$picksByYear = [];
foreach ($picks as $pk) { $picksByYear[(int)($pk['season_year']??0)][] = $pk; }
ksort($picksByYear);

// Ranking
$rankingPosition = $rankingPoints = null;
if (in_array('ranking',$modules) && $league!=='') {
    try {
        $s=$pdo->prepare('SELECT id,ranking_points FROM teams WHERE league=? ORDER BY ranking_points DESC,id ASC');
        $s->execute([$league]);
        $rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
        $pos=1;
        foreach($rows as $r){ if((int)$r['id']===$teamId){$rankingPosition=$pos;$rankingPoints=(int)($r['ranking_points']??0);break;}$pos++;}
    } catch(Exception $e){}
}

// Averages
$avgAge = $avgOvr = null;
if (in_array('ai_avgs',$modules) && $players) {
    $sumA=$sumO=$n=0;
    foreach($players as $p){$sumA+=(int)($p['age']??0);$sumO+=(int)($p['ovr']??0);$n++;}
    if($n>0){$avgAge=$sumA/$n;$avgOvr=$sumO/$n;}
}

// Trades + items
$trades = [];
$tradeItems = []; // [trade_id]['from'|'to'][players|picks]
if (in_array('trades',$modules)) {
    try {
        $s=$pdo->prepare("SELECT t.id,t.updated_at,t.created_at,t.from_team_id,t.to_team_id,tf.city AS from_city,tf.name AS from_name,tf.photo_url AS from_photo,tt.city AS to_city,tt.name AS to_name,tt.photo_url AS to_photo FROM trades t LEFT JOIN teams tf ON tf.id=t.from_team_id LEFT JOIN teams tt ON tt.id=t.to_team_id WHERE t.status='accepted' AND (t.from_team_id=? OR t.to_team_id=?) ORDER BY t.updated_at DESC,t.id DESC LIMIT 10");
        $s->execute([$teamId,$teamId]);
        $trades=$s->fetchAll(PDO::FETCH_ASSOC)?:[];

        if ($trades) {
            $tradeIds = array_column($trades,'id');
            $in = implode(',', array_fill(0, count($tradeIds), '?'));
            $si = $pdo->prepare("SELECT ti.trade_id,ti.from_team,p.name AS pname,p.position AS ppos,p.ovr AS povr,pk.season_year AS pk_year,pk.round AS pk_round,pk.original_team_id AS pk_orig,ot.name AS pk_orig_name FROM trade_items ti LEFT JOIN players p ON p.id=ti.player_id LEFT JOIN picks pk ON pk.id=ti.pick_id LEFT JOIN teams ot ON ot.id=pk.original_team_id WHERE ti.trade_id IN ($in)");
            $si->execute($tradeIds);
            $rawItems = $si->fetchAll(PDO::FETCH_ASSOC)?:[];
            foreach($rawItems as $ri){
                $tid=(int)$ri['trade_id'];
                $side=$ri['from_team']?'from':'to';
                if(!isset($tradeItems[$tid])) $tradeItems[$tid]=['from'=>[],'to'=>[]];
                $tradeItems[$tid][$side][]=$ri;
            }
        }
    } catch(Exception $e){ $trades=[]; }
}

function ovrColor(int $ovr): string {
    if ($ovr>=90) return '#f59e0b';
    if ($ovr>=80) return '#22c55e';
    if ($ovr>=70) return '#3b82f6';
    return '#868690';
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="<?= htmlspecialchars($primary) ?>">
    <title><?= htmlspecialchars($teamDisplayName) ?> — FBA</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --p:  <?= htmlspecialchars($primary) ?>;
            --s:  <?= htmlspecialchars($secondary) ?>;
            --ps: color-mix(in srgb, var(--p) 14%, transparent);
            --pb: color-mix(in srgb, var(--p) 30%, transparent);
            --f:  'Poppins', system-ui, sans-serif;
            --r:  14px;
            --rs: 10px;
            --ease: cubic-bezier(.2,.8,.2,1);
            --t: 200ms;
        }
        [data-theme="dark"] {
            --bg:  #080809;
            --c1:  #111114;
            --c2:  #17171b;
            --c3:  #1f1f24;
            --bd:  rgba(255,255,255,.07);
            --bd2: rgba(255,255,255,.12);
            --tx:  #f0f0f3;
            --tx2: #8a8a96;
            --tx3: #46464f;
        }
        [data-theme="light"] {
            --bg:  #f4f5f9;
            --c1:  #ffffff;
            --c2:  #f0f1f6;
            --c3:  #e7e9f0;
            --bd:  #e2e4ec;
            --bd2: #c8ccd8;
            --tx:  #111217;
            --tx2: #5b6270;
            --tx3: #9399a8;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body { font-family: var(--f); background: var(--bg); color: var(--tx); min-height: 100vh; -webkit-font-smoothing: antialiased; transition: background .25s, color .25s; }

        .bg-glow {
            position: fixed; inset: 0; pointer-events: none; z-index: 0;
            background:
                radial-gradient(ellipse 700px 450px at 10% -5%, color-mix(in srgb,var(--p) 16%,transparent), transparent 60%),
                radial-gradient(ellipse 500px 350px at 90% -5%, color-mix(in srgb,var(--s) 10%,transparent), transparent 60%);
            opacity: 1;
            transition: opacity .25s;
        }
        [data-theme="light"] .bg-glow { opacity: .4; }

        .wrap { position: relative; z-index: 1; max-width: 960px; margin: 0 auto; padding: 0 16px 60px; }

        /* HERO */
        .hero-shell { background: var(--c1); border-bottom: 1px solid var(--bd); }
        .hero { padding: 40px 0 36px; display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
        .hero-logo {
            width: 90px; height: 90px; border-radius: 999px;
            object-fit: cover; border: 3px solid var(--p);
            box-shadow: 0 0 36px color-mix(in srgb,var(--p) 30%,transparent);
            flex-shrink: 0;
        }
        .hero-info { flex: 1; min-width: 0; }
        .hero-league {
            display: inline-flex; align-items: center; gap: 5px;
            background: var(--ps); border: 1px solid var(--pb); color: var(--p);
            font-size: 9px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase;
            padding: 3px 9px; border-radius: 999px; margin-bottom: 8px;
        }
        .hero-name {
            font-size: clamp(22px,5vw,36px); font-weight: 900; letter-spacing:-.03em; line-height:1.05;
            background: linear-gradient(120deg, var(--tx) 40%, var(--p));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .hero-city { font-size: 13px; color: var(--tx2); margin-top: 5px; }
        .hero-pills { display: flex; gap: 7px; flex-wrap: wrap; margin-top: 12px; }
        .pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: 999px; font-size: 11px; font-weight: 600; background: var(--c2); border: 1px solid var(--bd2); color: var(--tx2); }
        .pill.accent { background: var(--ps); border-color: var(--pb); color: var(--p); }

        /* Theme toggle */
        .theme-btn {
            width: 34px; height: 34px; border-radius: 9px;
            background: var(--c2); border: 1px solid var(--bd);
            color: var(--tx2); cursor: pointer; font-size: 16px;
            display: flex; align-items: center; justify-content: center;
            transition: all var(--t) var(--ease); align-self: flex-start; flex-shrink: 0;
        }
        .theme-btn:hover { border-color: var(--pb); color: var(--p); }

        /* SECTION */
        .section { margin-top: 28px; }
        .section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .section-title { display: flex; align-items: center; gap: 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .9px; color: var(--tx2); }
        .section-title i { color: var(--p); font-size: 14px; }
        .section-count { font-size: 11px; font-weight: 600; background: var(--c2); border: 1px solid var(--bd); padding: 2px 9px; border-radius: 999px; color: var(--tx3); }

        /* STAT CARDS */
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px,1fr)); gap: 10px; }
        .stat-card {
            background: var(--c1); border: 1px solid var(--bd); border-radius: var(--r);
            padding: 18px 16px; position: relative; overflow: hidden;
            transition: border-color var(--t) var(--ease), transform var(--t) var(--ease);
        }
        .stat-card::before { content:''; position:absolute; top:0;left:0;right:0;height:2px; background:linear-gradient(90deg,var(--p),var(--s)); }
        .stat-card:hover { border-color: var(--bd2); transform: translateY(-2px); }
        .stat-icon { width:32px;height:32px;border-radius:8px;background:var(--ps);display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--p);margin-bottom:12px; }
        .stat-val { font-size:28px;font-weight:900;line-height:1;color:var(--tx); }
        .stat-val.accent { color:var(--p); }
        .stat-label { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--tx2);margin-top:4px; }
        .stat-sub { font-size:11px;color:var(--tx3);margin-top:2px; }

        /* STARTERS STRIP */
        .starters-strip { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 4px; scrollbar-width: none; }
        .starters-strip::-webkit-scrollbar { display: none; }
        .starter-chip {
            flex-shrink: 0;
            background: var(--c1); border: 1px solid var(--bd); border-radius: var(--r);
            padding: 14px 16px; display: flex; flex-direction: column; align-items: center; gap: 8px;
            min-width: 110px; text-align: center;
            transition: border-color var(--t) var(--ease), transform var(--t) var(--ease);
            position: relative; overflow: hidden;
        }
        .starter-chip::before { content:''; position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--p),var(--s)); }
        .starter-chip:hover { border-color: var(--pb); transform: translateY(-2px); }
        .starter-photo {
            width: 56px; height: 56px; border-radius: 50%;
            object-fit: cover; border: 2px solid var(--bd2);
            background: var(--c3);
        }
        .starter-photo-placeholder {
            width: 56px; height: 56px; border-radius: 50%;
            background: var(--ps); border: 2px solid var(--pb);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 900; color: var(--p);
        }
        .starter-pos { display:inline-flex;align-items:center;justify-content:center;padding:2px 7px;border-radius:5px;background:var(--ps);color:var(--p);font-size:9px;font-weight:800;letter-spacing:.3px;text-transform:uppercase; }
        .starter-name { font-size:12px;font-weight:700;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%; }
        .starter-ovr { font-size:18px;font-weight:900;line-height:1; }
        .starter-age { font-size:11px;color:var(--tx2); }

        /* ROSTER TABLE */
        .card-box { background:var(--c1);border:1px solid var(--bd);border-radius:var(--r);overflow:hidden; }
        .ftable { width:100%;border-collapse:collapse; }
        .ftable th { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--tx3);padding:10px 14px;text-align:left;border-bottom:1px solid var(--bd); }
        .ftable td { padding:11px 14px;font-size:13px;color:var(--tx2);border-bottom:1px solid var(--bd); }
        .ftable tr:last-child td { border-bottom:none; }
        .ftable tbody tr:hover td { background:var(--c2); }
        .ftable td strong { color:var(--tx);font-weight:600; }
        .pos-badge { display:inline-flex;align-items:center;justify-content:center;padding:2px 7px;border-radius:5px;background:var(--ps);color:var(--p);font-size:9px;font-weight:800;letter-spacing:.3px;text-transform:uppercase; }

        /* PICKS compact */
        .picks-grid { display: flex; flex-wrap: wrap; gap: 6px; }
        .pick-tag {
            display: inline-flex; flex-direction: column;
            background: var(--c1); border: 1px solid var(--bd);
            border-radius: var(--rs); padding: 7px 11px;
            transition: border-color var(--t) var(--ease);
            min-width: 80px;
        }
        .pick-tag:hover { border-color: var(--pb); }
        .pick-tag-top { display: flex; align-items: baseline; gap: 4px; }
        .pick-year { font-size: 10px; font-weight: 700; color: var(--tx3); }
        .pick-round { font-size: 14px; font-weight: 900; color: var(--tx); }
        .pick-origin { font-size: 10px; color: var(--tx2); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 120px; }
        .pick-own-label { color: var(--p); font-weight: 700; }

        /* TRADES */
        .trade-list { display:flex;flex-direction:column;gap:8px; }
        .trade-card {
            background:var(--c1);border:1px solid var(--bd);border-radius:var(--r);overflow:hidden;
            transition: border-color var(--t) var(--ease);
        }
        .trade-card:hover { border-color: var(--bd2); }
        .trade-header {
            display:flex;align-items:center;gap:12px;padding:13px 16px;cursor:pointer;
            user-select: none;
        }
        .trade-team-block { display:flex;align-items:center;gap:8px;flex:1;min-width:0; }
        .trade-logo { width:32px;height:32px;border-radius:8px;object-fit:cover;border:1px solid var(--bd2);background:var(--c3);flex-shrink:0; }
        .trade-tname { font-size:13px;font-weight:600;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        .trade-arrow-icon { color:var(--p);font-size:16px;flex-shrink:0; }
        .trade-date { font-size:11px;color:var(--tx3);white-space:nowrap;flex-shrink:0; }
        .trade-chevron { font-size:13px;color:var(--tx3);flex-shrink:0;transition:transform var(--t) var(--ease); }
        .trade-card.open .trade-chevron { transform:rotate(180deg); }

        .trade-body { display:none; border-top:1px solid var(--bd); }
        .trade-card.open .trade-body { display:block; }
        .trade-cols { display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--bd); }
        .trade-col { background:var(--c2);padding:14px 16px; }
        .trade-col-label { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--tx3);margin-bottom:10px; }
        .trade-item-row { display:flex;align-items:center;gap:7px;font-size:12px;color:var(--tx2);margin-bottom:7px; }
        .trade-item-row:last-child { margin-bottom:0; }
        .trade-item-row i { color:var(--p);font-size:12px;flex-shrink:0; }
        .trade-item-row strong { color:var(--tx);font-weight:600; }
        .trade-empty { font-size:12px;color:var(--tx3);font-style:italic; }

        /* FOOTER */
        .footer { text-align:center;padding:28px 0 0;font-size:12px;color:var(--tx3);border-top:1px solid var(--bd);margin-top:36px;display:flex;flex-direction:column;align-items:center;gap:5px; }
        .footer-logo { width:26px;height:26px;border-radius:7px;background:var(--ps);color:var(--p);font-size:10px;font-weight:900;display:flex;align-items:center;justify-content:center; }

        /* EMPTY */
        .empty-state { padding:28px;text-align:center;color:var(--tx3);font-size:13px; }
        .empty-state i { font-size:26px;display:block;margin-bottom:8px;opacity:.5; }

        /* RESPONSIVE */
        @media(max-width:768px){
            .wrap { padding-left:14px;padding-right:14px; }
            .stats-row { grid-template-columns:1fr 1fr; }
            .starter-chip { min-width:100px; }
            .trade-cols { grid-template-columns:1fr; gap:0; }
            .trade-col:first-child { border-bottom:1px solid var(--bd); }
        }
        @media(max-width:560px){
            .hero { padding:22px 0 18px;gap:14px; }
            .hero-logo { width:64px;height:64px; }
            .hero-name { font-size:22px; }
            .hero-city { font-size:12px; }
            .hero-pills { gap:5px; }
            .pill { font-size:10px;padding:3px 9px; }
            .theme-btn { width:30px;height:30px;font-size:14px; }
            .section { margin-top:22px; }
            .stat-card { padding:14px 12px; }
            .stat-val { font-size:24px; }
            .stat-icon { width:28px;height:28px;font-size:13px;margin-bottom:10px; }
            .starter-chip { min-width:88px;padding:10px 10px; }
            .starter-photo, .starter-photo-placeholder { width:46px;height:46px; }
            .starter-ovr { font-size:15px; }
            .starter-name { font-size:11px; }
            .starter-age { font-size:10px; }
            .pick-tag { padding:6px 9px; }
            .pick-round { font-size:13px; }
            .trade-date { display:none; }
            .trade-tname { font-size:12px; }
            .ftable th, .ftable td { padding:9px 10px;font-size:12px; }
        }
        @media(max-width:380px){
            .wrap { padding-left:10px;padding-right:10px; }
            .stats-row { grid-template-columns:1fr 1fr; }
            .hero-logo { width:54px;height:54px; }
        }
    </style>
</head>
<body>
<div class="bg-glow"></div>

<!-- HERO -->
<div class="hero-shell">
    <div class="wrap">
        <div class="hero">
            <img src="<?= htmlspecialchars($teamLogo) ?>" class="hero-logo"
                 alt="<?= htmlspecialchars($teamDisplayName) ?>" onerror="this.src='/img/default-team.png'">
            <div class="hero-info">
                <?php if ($league !== ''): ?>
                <div class="hero-league"><i class="bi bi-shield-fill" style="font-size:8px"></i> <?= htmlspecialchars($league) ?></div>
                <?php endif; ?>
                <div class="hero-name"><?= htmlspecialchars($teamDisplayName) ?></div>
                <?php if ($teamCity !== ''): ?>
                <div class="hero-city"><i class="bi bi-geo-alt" style="font-size:11px"></i> <?= htmlspecialchars($teamCity) ?></div>
                <?php endif; ?>
                <div class="hero-pills">
                    <?php if ($championships > 0): ?>
                    <span class="pill accent"><i class="bi bi-trophy-fill" style="font-size:10px"></i> <?= $championships ?> título<?= $championships>1?'s':'' ?></span>
                    <?php endif; ?>
                    <?php if ($rankingPosition): ?>
                    <span class="pill"><i class="bi bi-bar-chart-fill" style="font-size:10px"></i> #<?= $rankingPosition ?> ranking</span>
                    <?php endif; ?>
                    <?php if ($players): ?>
                    <span class="pill"><i class="bi bi-people-fill" style="font-size:10px"></i> <?= count($players) ?> jogadores</span>
                    <?php endif; ?>
                </div>
            </div>
            <button class="theme-btn" id="themeBtn" title="Alternar tema">
                <i class="bi bi-moon" id="themeIcon"></i>
            </button>
        </div>
    </div>
</div>

<div class="wrap">

    <!-- STATS -->
    <?php if (in_array('titles',$modules)||in_array('ranking',$modules)||in_array('ai_avgs',$modules)): ?>
    <div class="section">
        <div class="stats-row">
            <?php if (in_array('titles',$modules)): ?>
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-trophy-fill"></i></div>
                <div class="stat-val accent"><?= $championships ?></div>
                <div class="stat-label">Títulos</div>
            </div>
            <?php endif; ?>
            <?php if (in_array('ranking',$modules)): ?>
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-bar-chart-fill"></i></div>
                <div class="stat-val"><?= $rankingPosition ? '#'.$rankingPosition : '—' ?></div>
                <div class="stat-label">Ranking</div>
                <div class="stat-sub"><?= $rankingPoints !== null ? $rankingPoints.' pts' : 'Sem dados' ?></div>
            </div>
            <?php endif; ?>
            <?php if (in_array('ai_avgs',$modules)): ?>
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-activity"></i></div>
                <div class="stat-val"><?= $avgOvr!==null ? number_format($avgOvr,1,',','.') : '—' ?></div>
                <div class="stat-label">OVR Médio</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-calendar3"></i></div>
                <div class="stat-val"><?= $avgAge!==null ? number_format($avgAge,1,',','.') : '—' ?></div>
                <div class="stat-label">Idade Média</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- LINEUP (strip) — mostrado acima do elenco -->
    <?php if (in_array('lineup',$modules) && $starters): ?>
    <div class="section">
        <div class="section-head">
            <div class="section-title"><i class="bi bi-star-fill"></i> Titulares</div>
            <span class="section-count"><?= count($starters) ?></span>
        </div>
        <div class="starters-strip">
            <?php foreach($starters as $p):
                $ovr   = (int)($p['ovr'] ?? 0);
                $photo = $p['photo_url'] ?? '';
                $initials = strtoupper(substr($p['name'] ?? 'J', 0, 1));
            ?>
            <div class="starter-chip">
                <?php if ($photo): ?>
                <img src="<?= htmlspecialchars($photo) ?>" class="starter-photo"
                     alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <div class="starter-photo-placeholder" style="display:none"><?= $initials ?></div>
                <?php else: ?>
                <div class="starter-photo-placeholder"><?= $initials ?></div>
                <?php endif; ?>
                <span class="starter-pos"><?= htmlspecialchars($p['position'] ?? '') ?></span>
                <span class="starter-name"><?= htmlspecialchars($p['name'] ?? '') ?></span>
                <span class="starter-ovr" style="color:<?= ovrColor($ovr) ?>"><?= $ovr ?></span>
                <span class="starter-age"><?= (int)($p['age'] ?? 0) ?> anos</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ROSTER -->
    <?php if (in_array('roster',$modules)): ?>
    <div class="section">
        <div class="section-head">
            <div class="section-title"><i class="bi bi-people-fill"></i> Elenco Completo</div>
            <span class="section-count"><?= count($players) ?></span>
        </div>
        <?php if (!$players): ?>
        <div class="card-box"><div class="empty-state"><i class="bi bi-person-x"></i>Sem jogadores cadastrados.</div></div>
        <?php else: ?>
        <div class="card-box" style="overflow-x:auto">
            <table class="ftable">
                <thead>
                    <tr>
                        <th style="padding-left:18px">Jogador</th>
                        <th>Pos</th>
                        <th style="text-align:right">Idade</th>
                        <th style="text-align:right;padding-right:18px">OVR</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($players as $p):
                        $ovr = (int)($p['ovr'] ?? 0);
                        $isTitular = ($p['role'] ?? '') === 'Titular';
                    ?>
                    <tr>
                        <td style="padding-left:18px">
                            <div style="display:flex;align-items:center;gap:8px">
                                <?php if ($isTitular): ?>
                                <i class="bi bi-star-fill" style="font-size:10px;color:var(--p);flex-shrink:0"></i>
                                <?php endif; ?>
                                <strong><?= htmlspecialchars($p['name'] ?? '') ?></strong>
                            </div>
                        </td>
                        <td><span class="pos-badge"><?= htmlspecialchars($p['position'] ?? '') ?></span></td>
                        <td style="text-align:right"><?= (int)($p['age'] ?? 0) ?></td>
                        <td style="text-align:right;padding-right:18px">
                            <strong style="color:<?= ovrColor($ovr) ?>"><?= $ovr ?></strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- PICKS compact -->
    <?php if (in_array('picks',$modules)): ?>
    <div class="section">
        <div class="section-head">
            <div class="section-title"><i class="bi bi-calendar-check-fill"></i> Picks de Draft</div>
            <span class="section-count"><?= count($picks) ?></span>
        </div>
        <?php if (!$picks): ?>
        <div class="card-box"><div class="empty-state"><i class="bi bi-calendar-x"></i>Sem picks cadastradas.</div></div>
        <?php else: ?>
        <div class="picks-grid">
            <?php foreach($picksByYear as $yr => $yearPicks):
                foreach($yearPicks as $pk):
                    $isOwn = (int)($pk['original_team_id'] ?? 0) === $teamId;
                    $origName = trim(($pk['orig_city'] ?? '') . ' ' . ($pk['orig_name'] ?? ''));
                    if ($origName === '') $origName = 'Time #'.(int)($pk['original_team_id'] ?? 0);
            ?>
            <div class="pick-tag">
                <div class="pick-tag-top">
                    <span class="pick-year"><?= $yr ?></span>
                    <span class="pick-round">R<?= htmlspecialchars((string)($pk['round'] ?? '')) ?></span>
                </div>
                <div class="pick-origin <?= $isOwn ? 'pick-own-label' : '' ?>">
                    <?= $isOwn ? 'Própria' : htmlspecialchars($origName) ?>
                </div>
            </div>
            <?php endforeach; endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- TRADES com expand -->
    <?php if (in_array('trades',$modules)): ?>
    <div class="section">
        <div class="section-head">
            <div class="section-title"><i class="bi bi-arrow-left-right"></i> Trades Recentes</div>
            <span class="section-count"><?= count($trades) ?></span>
        </div>
        <?php if (!$trades): ?>
        <div class="card-box"><div class="empty-state"><i class="bi bi-arrow-left-right"></i>Nenhuma trade aceita ainda.</div></div>
        <?php else: ?>
        <div class="trade-list">
            <?php foreach($trades as $tr):
                $tid = (int)$tr['id'];
                $dateRaw = $tr['updated_at'] ?? $tr['created_at'] ?? '';
                $dateStr = '';
                if ($dateRaw) { try { $dateStr=(new DateTime($dateRaw))->format('d/m/Y'); } catch(Exception $e){} }
                $fromFull = trim(($tr['from_city']??'').' '.($tr['from_name']??''));
                $toFull   = trim(($tr['to_city']  ??'').' '.($tr['to_name']  ??''));
                $items    = $tradeItems[$tid] ?? ['from'=>[],'to'=>[]];
            ?>
            <div class="trade-card" id="tc-<?= $tid ?>">
                <div class="trade-header" onclick="toggleTrade(<?= $tid ?>)">
                    <div class="trade-team-block">
                        <img src="<?= htmlspecialchars($tr['from_photo']??'/img/default-team.png') ?>"
                             class="trade-logo" alt="" onerror="this.src='/img/default-team.png'">
                        <span class="trade-tname"><?= htmlspecialchars($fromFull) ?></span>
                    </div>
                    <div class="trade-arrow-icon"><i class="bi bi-arrow-left-right"></i></div>
                    <div class="trade-team-block">
                        <img src="<?= htmlspecialchars($tr['to_photo']??'/img/default-team.png') ?>"
                             class="trade-logo" alt="" onerror="this.src='/img/default-team.png'">
                        <span class="trade-tname"><?= htmlspecialchars($toFull) ?></span>
                    </div>
                    <?php if ($dateStr): ?><span class="trade-date"><?= $dateStr ?></span><?php endif; ?>
                    <i class="bi bi-chevron-down trade-chevron"></i>
                </div>
                <div class="trade-body">
                    <div class="trade-cols">
                        <!-- O que o "from" enviou -->
                        <div class="trade-col">
                            <div class="trade-col-label"><?= htmlspecialchars($fromFull ?: 'Time A') ?> enviou</div>
                            <?php if (!$items['from']): ?>
                            <div class="trade-empty">Nenhum item</div>
                            <?php else: foreach($items['from'] as $it): ?>
                            <div class="trade-item-row">
                                <?php if (!empty($it['pname'])): ?>
                                <i class="bi bi-person-fill"></i>
                                <span><strong><?= htmlspecialchars($it['pname']) ?></strong>
                                    <?php if (!empty($it['ppos'])): ?> · <span style="color:var(--tx3)"><?= htmlspecialchars($it['ppos']) ?></span><?php endif; ?>
                                    <?php if (!empty($it['povr'])): ?> · <span style="color:<?= ovrColor((int)$it['povr']) ?>;font-weight:700"><?= (int)$it['povr'] ?></span><?php endif; ?>
                                </span>
                                <?php elseif(!empty($it['pk_year'])): ?>
                                <i class="bi bi-calendar-check-fill"></i>
                                <span><strong><?= (int)$it['pk_year'] ?> R<?= htmlspecialchars((string)($it['pk_round']??'')) ?></strong>
                                    <?php if(!empty($it['pk_orig_name'])): ?> · <span style="color:var(--tx3)"><?= htmlspecialchars($it['pk_orig_name']) ?></span><?php endif; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <!-- O que o "to" enviou -->
                        <div class="trade-col">
                            <div class="trade-col-label"><?= htmlspecialchars($toFull ?: 'Time B') ?> enviou</div>
                            <?php if (!$items['to']): ?>
                            <div class="trade-empty">Nenhum item</div>
                            <?php else: foreach($items['to'] as $it): ?>
                            <div class="trade-item-row">
                                <?php if (!empty($it['pname'])): ?>
                                <i class="bi bi-person-fill"></i>
                                <span><strong><?= htmlspecialchars($it['pname']) ?></strong>
                                    <?php if (!empty($it['ppos'])): ?> · <span style="color:var(--tx3)"><?= htmlspecialchars($it['ppos']) ?></span><?php endif; ?>
                                    <?php if (!empty($it['povr'])): ?> · <span style="color:<?= ovrColor((int)$it['povr']) ?>;font-weight:700"><?= (int)$it['povr'] ?></span><?php endif; ?>
                                </span>
                                <?php elseif(!empty($it['pk_year'])): ?>
                                <i class="bi bi-calendar-check-fill"></i>
                                <span><strong><?= (int)$it['pk_year'] ?> R<?= htmlspecialchars((string)($it['pk_round']??'')) ?></strong>
                                    <?php if(!empty($it['pk_orig_name'])): ?> · <span style="color:var(--tx3)"><?= htmlspecialchars($it['pk_orig_name']) ?></span><?php endif; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- FOOTER -->
    <div class="footer">
        <div class="footer-logo">FBA</div>
        <span>Gerado pelo <strong style="color:var(--tx2)">FBA Manager</strong></span>
        <span>/times/<?= htmlspecialchars($slug) ?></span>
    </div>

</div><!-- /.wrap -->

<script>
    // Theme
    const html = document.documentElement;
    const icon = document.getElementById('themeIcon');
    const btn  = document.getElementById('themeBtn');
    function setTheme(t) {
        html.dataset.theme = t;
        localStorage.setItem('fba-pub-theme', t);
        icon.className = t === 'dark' ? 'bi bi-moon' : 'bi bi-sun';
    }
    setTheme(localStorage.getItem('fba-pub-theme') || 'dark');
    btn.addEventListener('click', () => setTheme(html.dataset.theme === 'dark' ? 'light' : 'dark'));

    // Trade toggle
    function toggleTrade(id) {
        const card = document.getElementById('tc-' + id);
        if (card) card.classList.toggle('open');
    }
</script>
</body>
</html>
