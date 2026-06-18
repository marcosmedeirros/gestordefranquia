<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
if (($user['user_type'] ?? '') !== 'admin') {
    $pdo2 = db();
    if (!hasAdminAccess($pdo2, $user['id'])) {
        header('Location: index.php'); exit;
    }
}
$pdo = db();

// ── Trades por time (regulares + multi) ──────────────────────────
$tradesRaw = $pdo->query("
    SELECT t.id AS team_id, CONCAT(t.city,' ',t.name) AS team_name, t.league,
           COUNT(DISTINCT tr.id) AS trade_count
    FROM teams t
    LEFT JOIN trades tr ON tr.status='accepted' AND (tr.from_team_id=t.id OR tr.to_team_id=t.id)
    GROUP BY t.id, t.city, t.name, t.league
")->fetchAll(PDO::FETCH_ASSOC);

// Add multi_trades if table exists
$multiMap = [];
try {
    $mt = $pdo->query("
        SELECT mtt.team_id, COUNT(DISTINCT mt.id) AS c
        FROM multi_trades mt
        JOIN multi_trade_teams mtt ON mtt.trade_id = mt.id
        WHERE mt.status = 'accepted'
        GROUP BY mtt.team_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mt as $r) $multiMap[$r['team_id']] = (int)$r['c'];
} catch (Exception $e) {}

$byLeague = [];
foreach ($tradesRaw as $r) {
    $total = (int)$r['trade_count'] + ($multiMap[$r['team_id']] ?? 0);
    $byLeague[$r['league']][] = [
        'name'  => $r['team_name'],
        'count' => $total,
    ];
}
foreach ($byLeague as $lg => &$arr) {
    usort($arr, fn($a,$b) => $b['count'] - $a['count']);
}
unset($arr);

$leagues = ['ELITE','NEXT','RISE','ROOKIE'];

// ── Pares que mais trocaram entre si ────────────────────────────
$pairsRaw = $pdo->query("
    SELECT tr.league,
           LEAST(tr.from_team_id, tr.to_team_id)    AS t1,
           GREATEST(tr.from_team_id, tr.to_team_id) AS t2,
           COUNT(*) AS c
    FROM trades tr
    WHERE tr.status = 'accepted'
      AND tr.from_team_id IS NOT NULL
      AND tr.to_team_id   IS NOT NULL
    GROUP BY tr.league, t1, t2
")->fetchAll(PDO::FETCH_ASSOC);

// Resolve team names (short = name only, long = city + name)
$teamNamesShort = [];
$teamNamesLong  = [];
foreach ($pdo->query("SELECT id, name, CONCAT(city,' ',name) AS full FROM teams")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $teamNamesShort[$r['id']] = $r['name'];
    $teamNamesLong[$r['id']]  = $r['full'];
}

$pairsByLeague = [];
foreach ($pairsRaw as $r) {
    $pairsByLeague[$r['league']][] = [
        'a'     => $teamNamesShort[$r['t1']] ?? "Time #{$r['t1']}",
        'b'     => $teamNamesShort[$r['t2']] ?? "Time #{$r['t2']}",
        'a_long'=> $teamNamesLong[$r['t1']]  ?? "Time #{$r['t1']}",
        'b_long'=> $teamNamesLong[$r['t2']]  ?? "Time #{$r['t2']}",
        'count' => (int)$r['c'],
    ];
}
foreach ($pairsByLeague as $lg => &$arr) {
    usort($arr, fn($a,$b) => $b['count'] - $a['count']);
}
unset($arr);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Infos & Forms · FBA</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:rgba(252,0,37,.10);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);--text:#f0f0f3;--text-2:#868690;--text-3:#48484f;--amber:#f59e0b;--green:#22c55e;--purple:#a855f7;--radius:14px;--font:'Poppins',sans-serif}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.topbar{position:sticky;top:0;z-index:300;height:54px;background:var(--panel);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 16px;gap:12px}
.topbar-logo{width:30px;height:30px;border-radius:8px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;color:#fff;flex-shrink:0}
.icon-btn{width:34px;height:34px;border-radius:10px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:15px;cursor:pointer;text-decoration:none;transition:all .2s}
.icon-btn:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
main{max-width:1100px;margin:0 auto;padding:28px 16px 80px}
h1{font-family:'Oswald',sans-serif;font-size:26px;font-weight:700;margin-bottom:6px}
.subtitle{font-size:13px;color:var(--text-2);margin-bottom:32px}
.section-label{font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-3);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.section-label i{color:var(--red)}
.leagues-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin-bottom:40px}
.league-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.league-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.league-badge{font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;letter-spacing:.5px}
.badge-ELITE{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.25)}
.badge-NEXT{background:rgba(99,102,241,.12);color:#818cf8;border:1px solid rgba(99,102,241,.25)}
.badge-RISE{background:rgba(34,197,94,.12);color:#4ade80;border:1px solid rgba(34,197,94,.25)}
.badge-ROOKIE{background:rgba(168,85,247,.12);color:#c084fc;border:1px solid rgba(168,85,247,.25)}
.card-section-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);padding:10px 16px 6px}
.rank-row{display:flex;align-items:center;gap:10px;padding:7px 16px;border-bottom:1px solid var(--border)}
.rank-row:last-child{border-bottom:none}
.rank-num{width:20px;font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;color:var(--text-3);flex-shrink:0;text-align:right}
.rank-num.gold{color:var(--amber)}
.rank-name{flex:1;font-size:12px;font-weight:500;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rank-val{font-family:'Oswald',sans-serif;font-size:15px;font-weight:700;flex-shrink:0}
.rank-val.hi{color:var(--red)}
.rank-val.lo{color:var(--text-3)}
.divider{height:1px;background:var(--border-md);margin:4px 0}
.pair-row{display:flex;align-items:center;gap:10px;padding:8px 16px;border-bottom:1px solid var(--border)}
.pair-row:last-child{border-bottom:none}
.pair-teams{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.pair-team{font-size:12px;font-weight:500;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pair-sep{font-size:9px;color:var(--text-3);font-weight:700;letter-spacing:.5px;padding:0 2px}
.pairs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:40px}
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-logo">FBA</div>
  <span style="font-weight:800;font-size:14px;flex:1">Infos & Forms</span>
  <a href="admin.php" class="icon-btn"><i class="bi bi-arrow-left"></i></a>
</div>

<main>
  <h1><i class="bi bi-graph-up-arrow" style="color:var(--red)"></i> Trades por Liga</h1>
  <p class="subtitle">Top 5 times que mais e menos fizeram trades aceitas (incluindo multi-trades)</p>

  <div class="section-label"><i class="bi bi-trophy-fill"></i> Mais ativos no mercado</div>
  <div class="leagues-grid">
    <?php foreach ($leagues as $lg):
      $arr = $byLeague[$lg] ?? [];
      $top5 = array_slice($arr, 0, 5);
      $bot5 = array_reverse(array_slice(array_reverse($arr), 0, 5));
    ?>
    <?php
      $copyText  = "🔥 *Mais trades — {$lg}*\n";
      foreach ($top5 as $i => $t) $copyText .= ($i+1).". {$t['name']} — {$t['count']}\n";
      $copyText .= "\n🧊 *Menos trades — {$lg}*\n";
      foreach ($bot5 as $i => $t) $copyText .= ($i+1).". {$t['name']} — {$t['count']}\n";
      $copyText = htmlspecialchars(str_replace('\n', "\n", $copyText), ENT_QUOTES);
    ?>
    <div class="league-card">
      <div class="league-header">
        <span class="league-badge badge-<?= $lg ?>"><?= $lg ?></span>
        <span style="font-size:12px;color:var(--text-2);flex:1"><?= count($arr) ?> times</span>
        <button class="copy-btn" data-text="<?= $copyText ?>" style="background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2);border-radius:8px;padding:4px 10px;font-size:11px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;transition:all .2s">
          <i class="bi bi-clipboard"></i> Copiar
        </button>
      </div>
      <div class="card-section-title">🔥 Mais trades</div>
      <?php foreach ($top5 as $i => $t): ?>
      <div class="rank-row">
        <span class="rank-num <?= $i===0?'gold':'' ?>"><?= $i+1 ?></span>
        <span class="rank-name" title="<?= htmlspecialchars($t['name']) ?>"><?= htmlspecialchars($t['name']) ?></span>
        <span class="rank-val hi"><?= $t['count'] ?></span>
      </div>
      <?php endforeach; ?>
      <div class="divider"></div>
      <div class="card-section-title">🧊 Menos trades</div>
      <?php foreach ($bot5 as $i => $t): ?>
      <div class="rank-row">
        <span class="rank-num"><?= $i+1 ?></span>
        <span class="rank-name" title="<?= htmlspecialchars($t['name']) ?>"><?= htmlspecialchars($t['name']) ?></span>
        <span class="rank-val lo"><?= $t['count'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</main>
  <h1 style="margin-top:16px"><i class="bi bi-arrow-left-right" style="color:var(--red)"></i> Pares que mais trocaram</h1>
  <p class="subtitle">Top 5 duplas com mais e menos trades aceitas entre si por liga</p>

  <div class="pairs-grid">
    <?php foreach ($leagues as $lg):
      $arr   = $pairsByLeague[$lg] ?? [];
      $top5p = array_slice($arr, 0, 5);
      $bot5p = array_reverse(array_slice(array_reverse($arr), 0, 5));
      $copyP  = "🤝 *Pares mais ativos — {$lg}*\n";
      foreach ($top5p as $i => $p) $copyP .= ($i+1).". {$p['a_long']} × {$p['b_long']} — {$p['count']}\n";
      $copyP .= "\n❄️ *Pares menos ativos — {$lg}*\n";
      foreach ($bot5p as $i => $p) $copyP .= ($i+1).". {$p['a_long']} × {$p['b_long']} — {$p['count']}\n";
      $copyP = htmlspecialchars($copyP, ENT_QUOTES);
    ?>
    <div class="league-card">
      <div class="league-header">
        <span class="league-badge badge-<?= $lg ?>"><?= $lg ?></span>
        <span style="font-size:12px;color:var(--text-2);flex:1"><?= count($arr) ?> pares</span>
        <button class="copy-btn" data-text="<?= $copyP ?>" style="background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2);border-radius:8px;padding:4px 10px;font-size:11px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;transition:all .2s">
          <i class="bi bi-clipboard"></i> Copiar
        </button>
      </div>
      <div class="card-section-title">🤝 Mais parceiros</div>
      <?php foreach ($top5p as $i => $p): ?>
      <div class="pair-row">
        <span class="rank-num <?= $i===0?'gold':'' ?>"><?= $i+1 ?></span>
        <div class="pair-teams">
          <span class="pair-team" title="<?= htmlspecialchars($p['a_long']) ?>"><?= htmlspecialchars($p['a']) ?></span>
          <span class="pair-sep">× <?= htmlspecialchars($p['b']) ?></span>
        </div>
        <span class="rank-val hi"><?= $p['count'] ?></span>
      </div>
      <?php endforeach; ?>
      <div class="divider"></div>
      <div class="card-section-title">❄️ Menos parceiros</div>
      <?php foreach ($bot5p as $i => $p): ?>
      <div class="pair-row">
        <span class="rank-num"><?= $i+1 ?></span>
        <div class="pair-teams">
          <span class="pair-team" title="<?= htmlspecialchars($p['a_long']) ?>"><?= htmlspecialchars($p['a']) ?></span>
          <span class="pair-sep">× <?= htmlspecialchars($p['b']) ?></span>
        </div>
        <span class="rank-val lo"><?= $p['count'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

<script>
document.querySelectorAll('.copy-btn').forEach(btn => {
  btn.addEventListener('mouseover', () => { btn.style.borderColor='var(--red)'; btn.style.color='var(--red)'; });
  btn.addEventListener('mouseout',  () => { btn.style.borderColor='var(--border-md)'; btn.style.color='var(--text-2)'; });
  btn.addEventListener('click', () => {
    const text = btn.getAttribute('data-text');
    navigator.clipboard.writeText(text).then(() => {
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-check2"></i> Copiado!';
      btn.style.color = 'var(--green)';
      btn.style.borderColor = 'var(--green)';
      setTimeout(() => {
        btn.innerHTML = orig;
        btn.style.color = 'var(--text-2)';
        btn.style.borderColor = 'var(--border-md)';
      }, 2000);
    }).catch(() => {
      // fallback
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus(); ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      btn.innerHTML = '<i class="bi bi-check2"></i> Copiado!';
      btn.style.color = 'var(--green)';
      setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i> Copiar'; btn.style.color = 'var(--text-2)'; }, 2000);
    });
  });
});
</script>
</body>
</html>
