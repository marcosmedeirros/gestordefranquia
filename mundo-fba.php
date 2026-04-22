<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

// Time do usuário para sidebar
$myTeam = null;
try {
    $s = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
    $s->execute([$user['id']]);
    $myTeam = $s->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {}

// Ano da temporada para sidebar
$seasonDisplayYear = (int)date('Y');
try {
    $s = $pdo->prepare("
        SELECT s.season_number, sp.start_year
        FROM seasons s INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
        ORDER BY s.created_at DESC LIMIT 1
    ");
    $s->execute([$user['league']]);
    $cs = $s->fetch(PDO::FETCH_ASSOC);
    if ($cs && isset($cs['start_year'], $cs['season_number']))
        $seasonDisplayYear = (int)$cs['start_year'] + (int)$cs['season_number'] - 1;
} catch (Exception $e) {}

$awardLabels = [
    'mvp'               => 'MVP',
    'dpoy'              => 'Melhor Defensor',
    'smoy'              => '6º Homem',
    'mip'               => 'Mais Melhorado',
    'roy'               => 'Calouro do Ano',
    'fmvp'              => 'MVP das Finais',
    'all_nba_1'         => 'All-NBA 1ª Equipe',
    'all_nba_2'         => 'All-NBA 2ª Equipe',
    'all_nba_3'         => 'All-NBA 3ª Equipe',
    'all_defense_1'     => 'All-Defense 1ª',
    'all_defense_2'     => 'All-Defense 2ª',
    'all_star_1'        => 'All-Star 1ª Equipe',
    'all_star_2'        => 'All-Star 2ª Equipe',
    'all_star_3'        => 'All-Star 3ª Equipe',
    'all_rookie'        => 'All-Rookie',
    'coach_of_year'     => 'Técnico do Ano',
    'executive_of_year' => 'GM do Ano',
    'champion'          => 'Campeão',
    'runner_up'         => 'Vice-Campeão',
    'conf_champ_leste'  => 'Conf. Leste',
    'conf_champ_oeste'  => 'Conf. Oeste',
];

$leagueOrder = ['ELITE', 'NEXT', 'RISE'];
$leagueMeta  = [
    'ELITE' => ['color' => '#f59e0b', 'icon' => 'bi-trophy-fill',       'label' => 'Liga Elite'],
    'NEXT'  => ['color' => '#3b82f6', 'icon' => 'bi-lightning-charge-fill', 'label' => 'Liga Next'],
    'RISE'  => ['color' => '#22c55e', 'icon' => 'bi-graph-up-arrow',    'label' => 'Liga Rise'],
];
$leagueData  = [];

$capStmt = null;
try { $capStmt = $pdo->prepare('SELECT COALESCE(SUM(ovr),0) FROM (SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8) top8'); } catch (Exception $e) {}

foreach ($leagueOrder as $league) {
    // Times
    $teams = [];
    try {
        $s = $pdo->prepare("
            SELECT t.id, t.city, t.name, t.photo_url,
                   u.name AS owner_name
            FROM teams t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.league = ?
            ORDER BY t.name ASC
        ");
        $s->execute([$league]);
        $teams = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // CAP e titulares por time
    foreach ($teams as &$t) {
        $t['cap_top8'] = 0;
        if ($capStmt) {
            try { $capStmt->execute([$t['id']]); $t['cap_top8'] = (int)$capStmt->fetchColumn(); } catch (Exception $e) {}
        }
        $byPos = ['PG'=>null,'SG'=>null,'SF'=>null,'PF'=>null,'C'=>null];
        try {
            $s = $pdo->prepare("SELECT name, position, ovr, age FROM players WHERE team_id = ? AND role = 'Titular' ORDER BY FIELD(position,'PG','SG','SF','PF','C') LIMIT 10");
            $s->execute([$t['id']]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
                if (array_key_exists($p['position'], $byPos) && $byPos[$p['position']] === null)
                    $byPos[$p['position']] = $p;
            }
        } catch (Exception $e) {}
        $t['starters'] = $byPos;
    }
    unset($t);
    usort($teams, fn($a, $b) => $b['cap_top8'] - $a['cap_top8']);

    // Última temporada encerrada
    $lastSeason = null; $seasonYear = null;
    try {
        $s = $pdo->prepare("
            SELECT s.id, s.season_number, sp.start_year, s.year
            FROM seasons s INNER JOIN sprints sp ON s.sprint_id = sp.id
            WHERE s.league = ? AND s.status = 'completed'
            ORDER BY s.created_at DESC LIMIT 1
        ");
        $s->execute([$league]);
        $lastSeason = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($lastSeason) {
            $seasonYear = isset($lastSeason['start_year'], $lastSeason['season_number'])
                ? (int)$lastSeason['start_year'] + (int)$lastSeason['season_number'] - 1
                : (int)($lastSeason['year'] ?? 0);
        }
    } catch (Exception $e) {}

    // Campeão e vice — busca em season_history (fonte principal) e playoff_results como fallback
    $champion = null; $runnerUp = null;
    try {
        $s = $pdo->prepare("
            SELECT sh.champion_team_id, sh.runner_up_team_id, sh.year AS hist_year,
                   tc.city AS champ_city, tc.name AS champ_name, tc.photo_url AS champ_photo, uc.name AS champ_owner,
                   tr.city AS ru_city,   tr.name AS ru_name,   tr.photo_url AS ru_photo,   ur.name AS ru_owner
            FROM season_history sh
            LEFT JOIN teams tc ON sh.champion_team_id = tc.id
            LEFT JOIN users uc ON tc.user_id = uc.id
            LEFT JOIN teams tr ON sh.runner_up_team_id = tr.id
            LEFT JOIN users ur ON tr.user_id = ur.id
            WHERE sh.league = ?
            ORDER BY sh.year DESC, sh.sprint_number DESC, sh.season_number DESC
            LIMIT 1
        ");
        $s->execute([$league]);
        $sh = $s->fetch(PDO::FETCH_ASSOC);
        if ($sh && $sh['champion_team_id']) {
            $champion = ['city'=>$sh['champ_city'],'team_name'=>$sh['champ_name'],'photo_url'=>$sh['champ_photo'],'owner_name'=>$sh['champ_owner']];
            if (!$seasonYear && !empty($sh['hist_year'])) $seasonYear = (int)$sh['hist_year'];
        }
        if ($sh && $sh['runner_up_team_id']) {
            $runnerUp = ['city'=>$sh['ru_city'],'team_name'=>$sh['ru_name'],'photo_url'=>$sh['ru_photo'],'owner_name'=>$sh['ru_owner']];
        }
    } catch (Exception $e) {}
    // Fallback: playoff_results
    if (!$champion && $lastSeason) {
        try {
            $s = $pdo->prepare("
                SELECT pr.position, t.city, t.name AS team_name, t.photo_url, u.name AS owner_name
                FROM playoff_results pr
                JOIN teams t ON pr.team_id = t.id
                JOIN users u ON t.user_id = u.id
                WHERE pr.season_id = ? AND pr.position IN ('champion','runner_up')
            ");
            $s->execute([$lastSeason['id']]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                if ($r['position'] === 'champion') $champion = $r;
                else $runnerUp = $r;
            }
        } catch (Exception $e) {}
    }

    // Prêmios — busca na season_history (fonte principal)
    $awards = [];
    try {
        // Detectar se coluna roy_player existe
        $chk = $pdo->query("SHOW COLUMNS FROM season_history LIKE 'roy_player'");
        $hasRoy = $chk && $chk->rowCount() > 0;
        $roySel = $hasRoy ? ', sh.roy_player' : ', NULL AS roy_player';
        $s = $pdo->prepare("
            SELECT sh.mvp_player, sh.dpoy_player, sh.mip_player, sh.sixth_man_player {$roySel}
            FROM season_history sh
            WHERE sh.league = ?
            ORDER BY sh.year DESC, sh.sprint_number DESC, sh.season_number DESC
            LIMIT 1
        ");
        $s->execute([$league]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $map = ['mvp'=>'mvp_player','dpoy'=>'dpoy_player','mip'=>'mip_player','smoy'=>'sixth_man_player','roy'=>'roy_player'];
            foreach ($map as $type => $col) {
                if (!empty($row[$col])) $awards[] = ['award_type'=>$type,'player_name'=>$row[$col]];
            }
        }
    } catch (Exception $e) {}

    // Hall da Fama
    $hof = [];
    try {
        $s = $pdo->prepare("
            SELECT hof.titles,
                   COALESCE(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')), hof.team_name) AS display_team,
                   COALESCE(u.name, hof.gm_name) AS display_gm,
                   t.photo_url
            FROM hall_of_fame hof
            LEFT JOIN teams t ON hof.team_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE hof.league = ? AND hof.is_active = 1
            ORDER BY hof.titles DESC LIMIT 5
        ");
        $s->execute([$league]);
        $hof = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // Player count
    $playerCount = 0;
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM players p JOIN teams t ON p.team_id = t.id WHERE t.league = ?");
        $s->execute([$league]);
        $playerCount = (int)$s->fetchColumn();
    } catch (Exception $e) {}

    // Trades count
    $tradesCount = 0;
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM trades WHERE league = ? AND status = 'accepted'");
        $s->execute([$league]);
        $tradesCount = (int)$s->fetchColumn();
    } catch (Exception $e) {}

    // Avg CAP
    $avgCap = count($teams) > 0 ? (int)round(array_sum(array_column($teams, 'cap_top8')) / count($teams)) : 0;

    // Ranking da liga (pontos acumulados)
    $ranking = [];
    try {
        $s = $pdo->prepare("
            SELECT CONCAT(t.city,' ',t.name) AS team_name, t.photo_url,
                   COALESCE(t.ranking_points, 0) AS total_points,
                   COALESCE(t.ranking_titles, 0) AS total_titles,
                   u.name AS owner_name
            FROM teams t
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.league = ?
            ORDER BY COALESCE(t.ranking_points,0) DESC, COALESCE(t.ranking_titles,0) DESC, t.name ASC
        ");
        $s->execute([$league]);
        $ranking = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    $leagueData[$league] = compact('teams','lastSeason','seasonYear','champion','runnerUp','awards','hof','playerCount','tradesCount','avgCap','ranking');
}

$defaultTab = in_array($user['league'] ?? '', $leagueOrder) ? $user['league'] : 'ELITE';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#fc0025">
    <title>Mundo FBA - FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <style>
        :root {
            --red:        #fc0025;
            --red-soft:   rgba(252,0,37,.10);
            --red-glow:   rgba(252,0,37,.18);
            --bg:         #07070a;
            --panel:      #101013;
            --panel-2:    #16161a;
            --panel-3:    #1c1c21;
            --border:     rgba(255,255,255,.06);
            --border-md:  rgba(255,255,255,.10);
            --border-red: rgba(252,0,37,.22);
            --text:       #f0f0f3;
            --text-2:     #868690;
            --text-3:     #48484f;
            --amber:      #f59e0b;
            --green:      #22c55e;
            --blue:       #3b82f6;
            --sidebar-w:  260px;
            --font:       'Poppins', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }
        :root[data-theme="light"] {
            --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
            --border:#e3e6ee; --border-md:#d7dbe6; --text:#111217;
            --text-2:#5b6270; --text-3:#8b93a5;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

        /* ── Sidebar ────────────────────────────────────────── */
        .app { display: flex; min-height: 100vh; }
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
        .sb-section { font-size:10px; font-weight:600; letter-spacing:1.2px; text-transform:uppercase; color:var(--text-3); padding:12px 10px 5px; }
        .sb-nav a { display:flex; align-items:center; gap:10px; padding:9px 10px; border-radius:var(--radius-sm); color:var(--text-2); font-size:13px; font-weight:500; text-decoration:none; margin-bottom:2px; transition:all var(--t) var(--ease); }
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

        /* ── Topbar mobile ──────────────────────────────────── */
        .topbar { display:none; position:fixed; top:0; left:0; right:0; height:54px; background:var(--panel); border-bottom:1px solid var(--border); align-items:center; padding:0 16px; gap:12px; z-index:260; }
        .topbar-title { font-weight:700; font-size:15px; flex:1; }
        .topbar-title em { color:var(--red); font-style:normal; }
        .menu-btn { width:34px; height:34px; border-radius:9px; background:var(--panel-2); border:1px solid var(--border); color:var(--text); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:17px; }
        .sb-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.65); backdrop-filter:blur(4px); z-index:250; }
        .sb-overlay.show { display:block; }

        /* ── Main layout ────────────────────────────────────── */
        .main { margin-left:var(--sidebar-w); min-height:100vh; width:calc(100% - var(--sidebar-w)); display:flex; flex-direction:column; }
        .page-hero { padding:28px 32px 20px; border-bottom:1px solid var(--border); }
        .page-eyebrow { font-size:11px; font-weight:600; letter-spacing:1.4px; text-transform:uppercase; color:var(--red); margin-bottom:4px; }
        .page-title { font-size:22px; font-weight:800; display:flex; align-items:center; gap:10px; }
        .page-title i { color:var(--red); }
        .page-sub { font-size:13px; color:var(--text-2); margin-top:4px; }
        .content { padding:24px 32px 56px; flex:1; }

        /* ── League tabs ────────────────────────────────────── */
        .league-tabs { display:flex; gap:0; border-bottom:1px solid var(--border); margin-bottom:28px; justify-content:center; }
        .league-tab {
            display:flex; align-items:center; gap:8px;
            padding:12px 24px; font-size:13px; font-weight:600; color:var(--text-2);
            cursor:pointer; border:none; background:none; position:relative;
            transition:color var(--t) var(--ease); white-space:nowrap;
        }
        .league-tab i { font-size:14px; }
        .league-tab::after {
            content:''; position:absolute; bottom:-1px; left:0; right:0; height:2px;
            background:transparent; transition:background var(--t) var(--ease);
        }
        .league-tab.active { color:var(--text); }
        .league-tab[data-league="ELITE"].active { color:#f59e0b; }
        .league-tab[data-league="ELITE"].active::after { background:#f59e0b; }
        .league-tab[data-league="NEXT"].active  { color:#3b82f6; }
        .league-tab[data-league="NEXT"].active::after  { background:#3b82f6; }
        .league-tab[data-league="RISE"].active  { color:#22c55e; }
        .league-tab[data-league="RISE"].active::after  { background:#22c55e; }
        .league-pane { display:none; }
        .league-pane.active { display:block; }

        /* ── Section header ─────────────────────────────────── */
        .sec-hd {
            display:flex; align-items:center; gap:8px;
            font-size:10px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase;
            color:var(--text-3); margin-bottom:14px; margin-top:28px;
        }
        .sec-hd:first-child { margin-top:0; }
        .sec-hd i { font-size:13px; }

        /* ── Champion banner ────────────────────────────────── */
        .champ-grid { display:grid; grid-template-columns:repeat(2, minmax(0,380px)); gap:12px; margin-bottom:0; justify-content:center; }
        .champ-card {
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius); padding:20px;
            display:flex; align-items:center; gap:16px; position:relative; overflow:hidden;
        }
        .champ-card.champion { border-color:rgba(245,158,11,.35); }
        .champ-card.champion::before {
            content:''; position:absolute; top:-60px; right:-60px;
            width:180px; height:180px; border-radius:50%;
            background:radial-gradient(circle, rgba(245,158,11,.12) 0%, transparent 70%);
            pointer-events:none;
        }
        .champ-logo { width:56px; height:56px; border-radius:12px; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
        .champ-badge {
            width:56px; height:56px; border-radius:12px; flex-shrink:0;
            background:rgba(245,158,11,.12); border:1px solid rgba(245,158,11,.25);
            display:flex; align-items:center; justify-content:center;
            font-size:22px;
        }
        .champ-badge.silver { background:rgba(148,163,184,.1); border-color:rgba(148,163,184,.2); }
        .champ-info { min-width:0; }
        .champ-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--text-3); margin-bottom:3px; }
        .champ-label.gold { color:#f59e0b; }
        .champ-label.silver { color:#94a3b8; }
        .champ-name { font-size:15px; font-weight:800; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .champ-gm { font-size:12px; color:var(--text-2); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .champ-year { font-size:11px; font-weight:700; color:var(--text-3); margin-top:4px; }

        /* ── Awards grid ────────────────────────────────────── */
        .awards-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:10px; }
        .award-card {
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius-sm); padding:12px 14px;
        }
        .award-type { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--text-3); margin-bottom:4px; }
        .award-player { font-size:13px; font-weight:700; color:var(--text); }

        /* ── Teams list ─────────────────────────────────────── */
        .teams-list { display:flex; flex-direction:column; gap:6px; }
        .team-row {
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius); overflow:hidden;
            transition:border-color var(--t) var(--ease);
        }
        .team-row:hover { border-color:var(--border-md); }
        .team-row.open { border-color:var(--border-md); }
        .team-head {
            display:flex; align-items:center; gap:14px;
            padding:14px 18px; cursor:pointer; user-select:none;
        }
        .team-rank {
            width:28px; text-align:center; font-size:13px; font-weight:800;
            color:var(--text-3); flex-shrink:0;
        }
        .team-rank.gold   { color:#f59e0b; }
        .team-rank.silver { color:#94a3b8; }
        .team-rank.bronze { color:#c97b3b; }
        .team-logo {
            width:44px; height:44px; border-radius:10px; object-fit:cover;
            border:1px solid var(--border-md); flex-shrink:0;
            background:var(--panel-2);
        }
        .team-info { flex:1; min-width:0; }
        .team-name { font-size:14px; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .team-gm   { font-size:11px; color:var(--text-2); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .team-cap {
            display:flex; flex-direction:column; align-items:flex-end; flex-shrink:0; gap:2px;
        }
        .cap-val { font-size:16px; font-weight:800; color:var(--text); line-height:1; }
        .cap-lbl { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--text-3); }
        .team-toggle {
            width:28px; height:28px; border-radius:8px; flex-shrink:0;
            background:var(--panel-2); border:1px solid var(--border);
            display:flex; align-items:center; justify-content:center;
            font-size:12px; color:var(--text-2);
            transition:all var(--t) var(--ease);
        }
        .team-row.open .team-toggle { background:var(--red-soft); border-color:var(--border-red); color:var(--red); transform:rotate(180deg); }

        /* ── Starters table ─────────────────────────────────── */
        .starters-wrap {
            display:none; border-top:1px solid var(--border);
            padding:0 18px 14px;
        }
        .team-row.open .starters-wrap { display:block; }
        .starters-table { width:100%; border-collapse:collapse; margin-top:12px; }
        .starters-table th {
            font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px;
            color:var(--text-3); padding:6px 8px; text-align:left;
            border-bottom:1px solid var(--border);
        }
        .starters-table td { padding:9px 8px; font-size:13px; border-bottom:1px solid var(--border); }
        .starters-table tr:last-child td { border-bottom:none; }
        .pos-badge {
            display:inline-flex; align-items:center; justify-content:center;
            width:28px; height:22px; border-radius:6px;
            background:var(--panel-2); border:1px solid var(--border);
            font-size:10px; font-weight:800; color:var(--text-2); letter-spacing:.4px;
        }
        .ovr-badge {
            display:inline-block; padding:2px 8px; border-radius:6px;
            font-size:12px; font-weight:700;
        }
        .ovr-s { background:rgba(245,158,11,.12); color:#f59e0b; }
        .ovr-a { background:rgba(252,0,37,.10);  color:#fc6680; }
        .ovr-b { background:rgba(167,139,250,.12); color:#a78bfa; }
        .ovr-c { background:var(--panel-2); color:var(--text-2); }
        .empty-row td { color:var(--text-3); font-style:italic; font-size:12px; }

        /* ── Hall da Fama ───────────────────────────────────── */
        .hof-list { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px; }
        .hof-card {
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius-sm); padding:14px;
            display:flex; align-items:center; gap:12px;
        }
        .hof-logo { width:40px; height:40px; border-radius:9px; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; background:var(--panel-2); }
        .hof-info { min-width:0; flex:1; }
        .hof-team { font-size:13px; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .hof-gm   { font-size:11px; color:var(--text-2); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .hof-titles {
            display:flex; flex-direction:column; align-items:center; flex-shrink:0;
            background:rgba(245,158,11,.10); border:1px solid rgba(245,158,11,.2);
            border-radius:8px; padding:5px 10px; gap:1px;
        }
        .hof-num { font-size:18px; font-weight:900; color:#f59e0b; line-height:1; }
        .hof-lbl { font-size:9px; color:var(--amber); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }

        /* ── Stats strip ───────────────────────────────────── */
        .stats-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:24px; }
        .stat-pill {
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius-sm); padding:14px 16px;
            display:flex; flex-direction:column; align-items:center; gap:2px;
        }
        .stat-val { font-size:20px; font-weight:800; color:var(--text); line-height:1; }
        .stat-lbl { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.8px; color:var(--text-3); }

        /* ── Award chips ────────────────────────────────────── */
        .award-chips { display:flex; flex-wrap:wrap; gap:8px; }
        .award-chip {
            display:flex; align-items:center; gap:8px;
            background:var(--panel); border:1px solid var(--border);
            border-radius:30px; padding:8px 14px;
            min-width:0;
        }
        .chip-icon { font-size:16px; line-height:1; flex-shrink:0; }
        .chip-body { min-width:0; }
        .chip-lbl  { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--text-3); margin-bottom:1px; }
        .chip-name { font-size:12px; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px; }
        .chip-amber { border-color:rgba(245,158,11,.30); background:rgba(245,158,11,.06); }
        .chip-amber .chip-lbl { color:#f59e0b; }
        .chip-blue  { border-color:rgba(59,130,246,.30); background:rgba(59,130,246,.06); }
        .chip-blue  .chip-lbl { color:#3b82f6; }
        .chip-green { border-color:rgba(34,197,94,.30); background:rgba(34,197,94,.06); }
        .chip-green .chip-lbl { color:#22c55e; }
        .chip-purple{ border-color:rgba(167,139,250,.30); background:rgba(167,139,250,.06); }
        .chip-purple .chip-lbl { color:#a78bfa; }
        .chip-red   { border-color:rgba(252,0,37,.25); background:rgba(252,0,37,.06); }
        .chip-red   .chip-lbl { color:#fc6680; }
        .chip-gray  { border-color:var(--border-md); }

        /* ── Ranking table ─────────────────────────────────── */
        .ranking-table { width:100%; border-collapse:collapse; }
        .ranking-table th {
            font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px;
            color:var(--text-3); padding:8px 12px; text-align:left;
            border-bottom:1px solid var(--border);
        }
        .ranking-table th.right, .ranking-table td.right { text-align:right; }
        .ranking-table td { padding:11px 12px; font-size:13px; border-bottom:1px solid var(--border); vertical-align:middle; }
        .ranking-table tr:last-child td { border-bottom:none; }
        .ranking-table tr:hover td { background:var(--panel-2); }
        .rk-pos { width:32px; font-size:13px; font-weight:800; color:var(--text-3); }
        .rk-pos.gold   { color:#f59e0b; }
        .rk-pos.silver { color:#94a3b8; }
        .rk-pos.bronze { color:#c97b3b; }
        .rk-logo { width:30px; height:30px; border-radius:7px; object-fit:cover; border:1px solid var(--border-md); background:var(--panel-2); flex-shrink:0; }
        .rk-team { display:flex; align-items:center; gap:10px; }
        .rk-name { font-size:13px; font-weight:700; color:var(--text); }
        .rk-gm   { font-size:11px; color:var(--text-2); }
        .rk-pts  { font-size:16px; font-weight:800; color:var(--text); }
        .rk-titles { display:inline-flex; align-items:center; gap:4px; font-size:12px; font-weight:700; color:#f59e0b; }
        .ranking-wrap { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }

        /* ── Empty state ────────────────────────────────────── */
        .empty-state {
            text-align:center; padding:40px 20px; color:var(--text-3);
            background:var(--panel); border:1px solid var(--border); border-radius:var(--radius);
        }
        .empty-state i { font-size:28px; display:block; margin-bottom:10px; }
        .empty-state p { font-size:13px; margin:0; }

        /* ── Responsive ─────────────────────────────────────── */
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform:translateX(-260px); }
            .sidebar.open { transform:translateX(0); }
            .topbar { display:flex; }
            .main { margin-left:0; width:100%; padding-top:54px; }
            .page-hero { padding:20px 16px 16px; }
            .content { padding:16px 16px 48px; }
        }
        @media (max-width: 640px) {
            .champ-grid { grid-template-columns:1fr; }
            .stats-strip { grid-template-columns:1fr 1fr; }
            .league-tab { padding:10px 14px; font-size:12px; }
            .league-tab span { display:none; }
            .team-head { padding:12px 14px; gap:10px; }
            .cap-val { font-size:14px; }
            .starters-wrap { padding:0 14px 12px; }
            .starters-table td, .starters-table th { padding:7px 6px; font-size:12px; }
        }
    </style>
</head>
<body>
<div class="app">

<aside class="sidebar" id="sidebar">
    <div class="sb-brand">
        <div class="sb-logo">FBA</div>
        <div class="sb-brand-text">FBA Manager<span>Liga <?= htmlspecialchars($user['league']) ?></span></div>
    </div>

    <?php if ($myTeam): ?>
    <div class="sb-team">
        <img src="<?= htmlspecialchars(getTeamPhoto($myTeam['photo_url'] ?? null)) ?>"
             alt="<?= htmlspecialchars($myTeam['name']) ?>" onerror="this.src='/img/default-team.png'">
        <div>
            <div class="sb-team-name"><?= htmlspecialchars($myTeam['city'] . ' ' . $myTeam['name']) ?></div>
            <div class="sb-team-league"><?= htmlspecialchars($user['league']) ?></div>
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
        <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
        <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
        <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>

        <div class="sb-section">Liga</div>
        <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
        <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
        <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
        <a href="/mundo-fba.php" class="active"><i class="bi bi-globe2"></i> Mundo FBA</a>
        <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
        <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>

        <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
        <div class="sb-section">Admin</div>
        <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
        <a href="/punicoes.php"><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
        <a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a>
        <?php endif; ?>

        <div class="sb-section">Conta</div>
        <a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a>
    </nav>

    <button class="sb-theme-toggle" id="themeToggle" type="button">
        <i class="bi bi-moon"></i><span>Modo escuro</span>
    </button>

    <div class="sb-footer">
        <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>"
             alt="<?= htmlspecialchars($user['name']) ?>" class="sb-avatar"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name']) ?>&background=1c1c21&color=fc0025'">
        <span class="sb-username"><?= htmlspecialchars($user['name']) ?></span>
        <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>

<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Manager</em></div>
    <span style="font-size:11px;font-weight:700;color:var(--red)"><?= $seasonDisplayYear ?></span>
</header>

<main class="main">
    <div class="page-hero">
        <div class="page-eyebrow">Visão Geral</div>
        <h1 class="page-title"><i class="bi bi-globe2"></i> Mundo FBA</h1>
        <p class="page-sub">Acompanhe o que está acontecendo em todas as ligas — times, elencos e história.</p>
    </div>

    <div class="content">

        <!-- Abas das ligas -->
        <div class="league-tabs">
            <?php foreach ($leagueOrder as $league): $meta = $leagueMeta[$league]; ?>
            <button class="league-tab <?= $league === $defaultTab ? 'active' : '' ?>"
                    data-league="<?= $league ?>" onclick="switchTab('<?= $league ?>')">
                <i class="bi <?= $meta['icon'] ?>"></i>
                <span><?= $meta['label'] ?></span>
            </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($leagueOrder as $league):
            $d    = $leagueData[$league];
            $meta = $leagueMeta[$league];
            $col  = $meta['color'];
        ?>
        <div class="league-pane <?= $league === $defaultTab ? 'active' : '' ?>" id="pane-<?= $league ?>">

            <?php /* ── Stats strip ── */ ?>
            <div class="stats-strip">
                <div class="stat-pill">
                    <span class="stat-val"><?= count($d['teams']) ?></span>
                    <span class="stat-lbl">Times</span>
                </div>
                <div class="stat-pill">
                    <span class="stat-val"><?= $d['playerCount'] ?></span>
                    <span class="stat-lbl">Jogadores</span>
                </div>
                <div class="stat-pill">
                    <span class="stat-val"><?= $d['tradesCount'] ?></span>
                    <span class="stat-lbl">Trocas</span>
                </div>
                <div class="stat-pill">
                    <span class="stat-val"><?= $d['avgCap'] ?></span>
                    <span class="stat-lbl">CAP Médio</span>
                </div>
            </div>

            <?php /* ── Campeão da última temporada ── */ ?>
            <?php if ($d['champion'] || $d['runnerUp']): ?>
            <div class="sec-hd" style="color:<?= $col ?>">
                <i class="bi bi-trophy-fill" style="color:<?= $col ?>"></i>
                ÚLTIMA TEMPORADA<?= $d['seasonYear'] ? ' · ' . $d['seasonYear'] : '' ?>
            </div>
            <div class="champ-grid" style="margin-bottom:0">
                <?php if ($d['champion']): $c = $d['champion']; ?>
                <div class="champ-card champion">
                    <?php if ($c['photo_url']): ?>
                    <img class="champ-logo" src="<?= htmlspecialchars(getTeamPhoto($c['photo_url'])) ?>"
                         alt="" onerror="this.src='/img/default-team.png'">
                    <?php else: ?>
                    <div class="champ-badge">🏆</div>
                    <?php endif; ?>
                    <div class="champ-info">
                        <div class="champ-label gold"><i class="bi bi-trophy-fill me-1"></i>Campeão</div>
                        <div class="champ-name"><?= htmlspecialchars($c['city'] . ' ' . $c['team_name']) ?></div>
                        <div class="champ-gm"><i class="bi bi-person-fill me-1"></i><?= htmlspecialchars($c['owner_name']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($d['runnerUp']): $r = $d['runnerUp']; ?>
                <div class="champ-card">
                    <?php if ($r['photo_url']): ?>
                    <img class="champ-logo" src="<?= htmlspecialchars(getTeamPhoto($r['photo_url'])) ?>"
                         alt="" onerror="this.src='/img/default-team.png'">
                    <?php else: ?>
                    <div class="champ-badge silver">🥈</div>
                    <?php endif; ?>
                    <div class="champ-info">
                        <div class="champ-label silver">Vice-Campeão</div>
                        <div class="champ-name"><?= htmlspecialchars($r['city'] . ' ' . $r['team_name']) ?></div>
                        <div class="champ-gm"><i class="bi bi-person-fill me-1"></i><?= htmlspecialchars($r['owner_name']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php /* ── Prêmios individuais ── */ ?>
            <?php
            $chipDefs = [
                'mvp'           => ['icon'=>'⭐','label'=>'MVP',        'cls'=>'chip-amber'],
                'dpoy'          => ['icon'=>'🛡️','label'=>'DPOY',       'cls'=>'chip-blue'],
                'mip'           => ['icon'=>'📈','label'=>'MIP',        'cls'=>'chip-green'],
                'smoy'          => ['icon'=>'👤','label'=>'6º Homem',   'cls'=>'chip-purple'],
                'roy'           => ['icon'=>'🌟','label'=>'Calouro',    'cls'=>'chip-red'],
                'fmvp'          => ['icon'=>'🏆','label'=>'MVP Finals', 'cls'=>'chip-amber'],
                'coach_of_year' => ['icon'=>'👔','label'=>'Técnico',    'cls'=>'chip-gray'],
            ];
            $awardsByType = [];
            foreach ($d['awards'] as $aw) $awardsByType[$aw['award_type']] = $aw['player_name'];
            ?>
            <?php if (!empty($d['awards'])): ?>
            <div class="sec-hd" style="color:<?= $col ?>;margin-top:24px">
                <i class="bi bi-award-fill" style="color:<?= $col ?>"></i>
                PRÊMIOS INDIVIDUAIS<?= $d['seasonYear'] ? ' · ' . $d['seasonYear'] : '' ?>
            </div>
            <div class="award-chips">
                <?php foreach ($chipDefs as $type => $chip):
                    if (!isset($awardsByType[$type])) continue;
                ?>
                <div class="award-chip <?= $chip['cls'] ?>">
                    <span class="chip-icon"><?= $chip['icon'] ?></span>
                    <div class="chip-body">
                        <div class="chip-lbl"><?= $chip['label'] ?></div>
                        <div class="chip-name"><?= htmlspecialchars($awardsByType[$type] ?? '—') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php
                // Show any other awards not in chipDefs
                foreach ($d['awards'] as $aw):
                    if (isset($chipDefs[$aw['award_type']])) continue;
                    $label = $awardLabels[$aw['award_type']] ?? ucwords(str_replace('_',' ',$aw['award_type']));
                ?>
                <div class="award-chip chip-gray">
                    <span class="chip-icon">🎖️</span>
                    <div class="chip-body">
                        <div class="chip-lbl"><?= htmlspecialchars($label) ?></div>
                        <div class="chip-name"><?= htmlspecialchars($aw['player_name'] ?? '—') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php /* ── Ranking de times por CAP ── */ ?>
            <div class="sec-hd" style="color:<?= $col ?>;margin-top:24px">
                <i class="bi bi-list-ol" style="color:<?= $col ?>"></i>
                TIMES · RANKING POR CAP
            </div>

            <?php if (empty($d['teams'])): ?>
            <div class="empty-state">
                <i class="bi bi-people"></i>
                <p>Nenhum time cadastrado nesta liga.</p>
            </div>
            <?php else: ?>
            <div class="teams-list">
                <?php foreach ($d['teams'] as $rank => $t):
                    $rankNum = $rank + 1;
                    $rankClass = $rankNum === 1 ? 'gold' : ($rankNum === 2 ? 'silver' : ($rankNum === 3 ? 'bronze' : ''));
                    $rowId = 'team-' . $league . '-' . $t['id'];
                ?>
                <div class="team-row" id="<?= $rowId ?>">
                    <div class="team-head" onclick="toggleTeam('<?= $rowId ?>')">
                        <div class="team-rank <?= $rankClass ?>"><?= $rankNum ?></div>
                        <img class="team-logo"
                             src="<?= htmlspecialchars(getTeamPhoto($t['photo_url'] ?? null)) ?>"
                             alt="<?= htmlspecialchars($t['name']) ?>"
                             onerror="this.src='/img/default-team.png'">
                        <div class="team-info">
                            <div class="team-name"><?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?></div>
                            <div class="team-gm"><i class="bi bi-person-fill" style="font-size:10px;margin-right:3px"></i><?= htmlspecialchars($t['owner_name']) ?></div>
                        </div>
                        <div class="team-cap">
                            <div class="cap-val"><?= $t['cap_top8'] ?></div>
                            <div class="cap-lbl">CAP</div>
                        </div>
                        <div class="team-toggle"><i class="bi bi-chevron-down"></i></div>
                    </div>
                    <div class="starters-wrap">
                        <table class="starters-table">
                            <thead>
                                <tr>
                                    <th>POS</th>
                                    <th>Jogador</th>
                                    <th>OVR</th>
                                    <th>Idade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (['PG','SG','SF','PF','C'] as $pos):
                                    $p = $t['starters'][$pos] ?? null;
                                    if ($p):
                                        $ovr = (int)$p['ovr'];
                                        $ovrClass = $ovr >= 90 ? 'ovr-s' : ($ovr >= 85 ? 'ovr-a' : ($ovr >= 80 ? 'ovr-b' : 'ovr-c'));
                                ?>
                                <tr>
                                    <td><span class="pos-badge"><?= $pos ?></span></td>
                                    <td style="font-weight:600;color:var(--text)"><?= htmlspecialchars($p['name']) ?></td>
                                    <td><span class="ovr-badge <?= $ovrClass ?>"><?= $ovr ?></span></td>
                                    <td style="color:var(--text-2)"><?= htmlspecialchars($p['age'] ?? '—') ?></td>
                                </tr>
                                <?php else: ?>
                                <tr class="empty-row">
                                    <td><span class="pos-badge"><?= $pos ?></span></td>
                                    <td colspan="3" style="color:var(--text-3);font-style:italic">Vaga em aberto</td>
                                </tr>
                                <?php endif; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php /* ── Hall da Fama ── */ ?>
            <?php if (!empty($d['hof'])): ?>
            <div class="sec-hd" style="color:<?= $col ?>;margin-top:28px">
                <i class="bi bi-stars" style="color:<?= $col ?>"></i>
                HALL DA FAMA
            </div>
            <div class="hof-list">
                <?php foreach ($d['hof'] as $h): ?>
                <div class="hof-card">
                    <img class="hof-logo"
                         src="<?= htmlspecialchars(getTeamPhoto($h['photo_url'] ?? null)) ?>"
                         alt="" onerror="this.src='/img/default-team.png'">
                    <div class="hof-info">
                        <div class="hof-team"><?= htmlspecialchars(trim($h['display_team'] ?? '—')) ?></div>
                        <div class="hof-gm"><?= htmlspecialchars($h['display_gm'] ?? '—') ?></div>
                    </div>
                    <div class="hof-titles">
                        <div class="hof-num"><?= (int)$h['titles'] ?></div>
                        <div class="hof-lbl"><?= (int)$h['titles'] === 1 ? 'título' : 'títulos' ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php /* ── Ranking da liga ── */ ?>
            <?php if (!empty($d['ranking'])): ?>
            <div class="sec-hd" style="color:<?= $col ?>;margin-top:28px">
                <i class="bi bi-bar-chart-fill" style="color:<?= $col ?>"></i>
                RANKING DA LIGA
            </div>
            <div class="ranking-wrap">
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th style="width:36px">#</th>
                            <th>Time</th>
                            <th class="right">Títulos</th>
                            <th class="right">Pontos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($d['ranking'] as $ri => $rk):
                            $rPos = $ri + 1;
                            $rCls = $rPos === 1 ? 'gold' : ($rPos === 2 ? 'silver' : ($rPos === 3 ? 'bronze' : ''));
                            $pts  = (int)$rk['total_points'];
                            $tit  = (int)$rk['total_titles'];
                        ?>
                        <tr>
                            <td class="rk-pos <?= $rCls ?>"><?= $rPos ?></td>
                            <td>
                                <div class="rk-team">
                                    <img class="rk-logo"
                                         src="<?= htmlspecialchars(getTeamPhoto($rk['photo_url'] ?? null)) ?>"
                                         alt="" onerror="this.src='/img/default-team.png'">
                                    <div>
                                        <div class="rk-name"><?= htmlspecialchars($rk['team_name']) ?></div>
                                        <div class="rk-gm"><?= htmlspecialchars($rk['owner_name'] ?? '') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="right">
                                <?php if ($tit > 0): ?>
                                <span class="rk-titles">🏆 <?= $tit ?></span>
                                <?php else: ?>
                                <span style="color:var(--text-3)">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="right rk-pts"><?= $pts ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div><!-- /league-pane -->
        <?php endforeach; ?>

    </div><!-- /content -->
</main>
</div><!-- /app -->

<script>
    // Sidebar
    (function () {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sbOverlay');
        const menuBtn = document.getElementById('menuBtn');
        if (!sidebar) return;
        const close = () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); };
        if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); overlay.classList.add('show'); });
        if (overlay) overlay.addEventListener('click', close);
        document.querySelectorAll('.sb-nav a').forEach(a => a.addEventListener('click', close));
    })();

    // Theme toggle
    const themeKey = 'fba-theme';
    const themeToggle = document.getElementById('themeToggle');
    const applyTheme = (theme) => {
        if (theme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
        } else {
            document.documentElement.removeAttribute('data-theme');
            if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
        }
    };
    applyTheme(localStorage.getItem(themeKey) || 'dark');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            localStorage.setItem(themeKey, next);
            applyTheme(next);
        });
    }

    // League tab switching
    function switchTab(league) {
        document.querySelectorAll('.league-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.league-pane').forEach(p => p.classList.remove('active'));
        document.querySelector(`.league-tab[data-league="${league}"]`)?.classList.add('active');
        document.getElementById('pane-' + league)?.classList.add('active');
    }

    // Team row toggle
    function toggleTeam(rowId) {
        const row = document.getElementById(rowId);
        if (row) row.classList.toggle('open');
    }
</script>
</body>
</html>
