<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
$pdo  = db();

$teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if (!$teamId) {
    $stmtOwn = $pdo->prepare("SELECT id FROM teams WHERE user_id = ? LIMIT 1");
    $stmtOwn->execute([$user['id']]);
    $own = $stmtOwn->fetch(PDO::FETCH_ASSOC);
    $teamId = $own ? (int)$own['id'] : 0;
}
if (!$teamId) { header('Location: teams.php'); exit; }

$stmtT = $pdo->prepare("SELECT CONCAT(city,' ',name) AS full_name, city, name, league, photo_url FROM teams WHERE id = ?");
$stmtT->execute([$teamId]);
$teamInfo = $stmtT->fetch(PDO::FETCH_ASSOC);
if (!$teamInfo) { header('Location: teams.php'); exit; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#fc0025">
<title>Histórico · <?= htmlspecialchars($teamInfo['full_name']) ?></title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:rgba(252,0,37,.10);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;--border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--text:#f0f0f3;--text-2:#868690;--text-3:#48484f;--amber:#f59e0b;--green:#22c55e;--font:'Poppins',sans-serif;--radius:14px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.topbar{position:sticky;top:0;z-index:300;height:54px;background:var(--panel);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 16px;gap:12px}
.topbar-logo{width:30px;height:30px;border-radius:8px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;color:#fff}
.icon-btn{width:34px;height:34px;border-radius:10px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:15px;cursor:pointer;text-decoration:none;transition:all .2s}
.icon-btn:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
main{max-width:960px;margin:0 auto;padding:24px 16px 80px}
.hero{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.hero-logo{width:72px;height:72px;border-radius:16px;background:var(--panel-2);border:1px solid var(--border-md);display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-size:28px;font-weight:700;color:#fff;flex-shrink:0;overflow:hidden}
.hero-logo img{width:100%;height:100%;object-fit:contain;border-radius:14px}
.hero-name{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;color:var(--text)}
.league-badge{display:inline-block;background:var(--red-soft);border:1px solid rgba(252,0,37,.25);color:var(--red);border-radius:999px;font-size:10px;font-weight:700;padding:3px 10px;letter-spacing:.5px;margin-top:6px}
.section-title{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.section-title i{color:var(--red)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:24px}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}
.stat-card.highlight{border-color:rgba(252,0,37,.3);background:rgba(252,0,37,.05)}
.stat-label{font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px}
.stat-value{font-family:'Oswald',sans-serif;font-size:28px;font-weight:700;color:var(--text);line-height:1}
.stat-value.red{color:var(--red)}
.stat-value.amber{color:var(--amber)}
.stat-sub{font-size:11px;color:var(--text-2)}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.pos-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)}
.pos-row:last-child{border-bottom:none}
.pos-badge{width:36px;height:36px;border-radius:10px;background:var(--panel-2);border:1px solid var(--border-md);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:var(--red);flex-shrink:0}
.pos-name{font-size:13px;font-weight:600;flex:1}
.pos-ovr{font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;color:var(--text)}
.champ-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)}
.champ-row:last-child{border-bottom:none}
.ovr-pill{background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:2px 8px;font-size:11px;font-weight:700}
.award-row,.pl-row{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border)}
.award-row:last-child,.pl-row:last-child{border-bottom:none}
.progress-bar{height:6px;background:var(--panel-3);border-radius:99px;overflow:hidden;margin-top:6px}
.progress-fill{height:100%;background:var(--red);border-radius:99px;transition:width .6s}
.skeleton{background:linear-gradient(90deg,var(--panel-2) 25%,var(--panel-3) 50%,var(--panel-2) 75%);background-size:200% 100%;animation:shimmer 1.2s infinite;border-radius:8px}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.empty-state{text-align:center;padding:28px;color:var(--text-3);font-size:13px}
.year-table{width:100%;border-collapse:collapse;font-size:12px}
.year-table th{padding:7px 10px;text-align:left;color:var(--text-3);font-weight:600;border-bottom:1px solid var(--border);font-size:11px;text-transform:uppercase;letter-spacing:.4px}
.year-table td{padding:7px 10px;border-bottom:1px solid var(--border)}
.year-table tr:last-child td{border-bottom:none}
@media(max-width:640px){.two-col{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(2,1fr)}.hero-name{font-size:18px}}
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-logo">FBA</div>
  <span style="font-weight:800;font-size:14px;flex:1">Histórico do Clube</span>
  <a href="teams.php" class="icon-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
</div>

<main>
  <!-- Hero -->
  <div class="hero">
    <div class="hero-logo" id="hero-logo">
      <?php if (!empty($teamInfo['photo_url'])): ?>
        <img src="<?= htmlspecialchars($teamInfo['photo_url']) ?>" alt="Logo">
      <?php else: ?>
        <?= mb_strtoupper(mb_substr($teamInfo['city'] ?? '?', 0, 1)) ?>
      <?php endif; ?>
    </div>
    <div>
      <div class="hero-name"><?= htmlspecialchars($teamInfo['full_name']) ?></div>
      <div style="font-size:12px;color:var(--text-2);margin-top:4px" id="hero-owner">Carregando...</div>
      <span class="league-badge"><?= htmlspecialchars($teamInfo['league']) ?></span>
    </div>
  </div>

  <!-- Stats gerais -->
  <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Visão Geral</div>
  <div class="stats-grid" id="stats-grid">
    <?php for($i=0;$i<8;$i++): ?>
    <div class="stat-card"><div class="skeleton" style="height:11px;width:60%;margin-bottom:8px"></div><div class="skeleton" style="height:30px;width:50%"></div></div>
    <?php endfor; ?>
  </div>

  <!-- Playoffs & Regular -->
  <div class="section-title"><i class="bi bi-trophy-fill"></i> Desempenho por Fase</div>
  <div class="panel" id="phases-panel">
    <div class="skeleton" style="height:200px;border-radius:8px"></div>
  </div>

  <!-- Jogadores destaque + GM Stats -->
  <div class="two-col">
    <div class="panel">
      <div class="section-title"><i class="bi bi-person-fill"></i> Jogadores de Destaque</div>
      <div id="players-content"><div class="skeleton" style="height:90px;border-radius:8px"></div></div>
    </div>
    <div class="panel">
      <div class="section-title"><i class="bi bi-person-badge-fill"></i> Estatísticas do GM</div>
      <div id="gm-content"><div class="skeleton" style="height:90px;border-radius:8px"></div></div>
    </div>
  </div>

  <!-- Melhor por posição -->
  <div class="panel">
    <div class="section-title"><i class="bi bi-people-fill"></i> Hall da Fama — Melhor por Posição</div>
    <div id="best-by-pos"><div class="skeleton" style="height:180px;border-radius:8px"></div></div>
  </div>

  <!-- Média OVR/Idade por ano -->
  <div class="panel">
    <div class="section-title"><i class="bi bi-graph-up"></i> Evolução por Temporada</div>
    <div id="avg-by-year"><div class="skeleton" style="height:120px;border-radius:8px"></div></div>
  </div>

  <!-- Prêmios individuais -->
  <div class="panel">
    <div class="section-title"><i class="bi bi-award-fill"></i> Prêmios Individuais</div>
    <div id="awards-content"><div class="skeleton" style="height:60px;border-radius:8px"></div></div>
  </div>

  <!-- Elenco campeão -->
  <div class="panel" id="champ-panel" style="display:none">
    <div class="section-title"><i class="bi bi-stars"></i> Elenco Campeão — <span id="champ-year"></span></div>
    <div id="champ-roster"></div>
  </div>
</main>

<script>
const TEAM_ID = <?= $teamId ?>;
const AWARD_LABELS = { mvp:'MVP', dpoy:'DPOY', mip:'MIP', '6th_man':'6º Homem', roy:'ROY' };

function esc(s){ if(!s)return''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function statCard(label, value, sub='', red=false, amber=false){
  const cls = red && value>0 ? 'highlight' : '';
  const valCls = red && value>0 ? 'red' : amber && value>0 ? 'amber' : '';
  return `<div class="stat-card ${cls}">
    <div class="stat-label">${label}</div>
    <div class="stat-value ${valCls}">${value ?? '—'}</div>
    ${sub ? `<div class="stat-sub">${sub}</div>` : ''}
  </div>`;
}

function phaseRow(icon, label, value, color='var(--text-2)', bold=false){
  if(!value) return '';
  return `<div class="pl-row">
    <span style="font-size:13px${bold?';font-weight:700;color:var(--text)':''}">${icon} ${label}</span>
    <span style="font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;color:${color}">${value}×</span>
  </div>`;
}

async function load(){
  const res  = await fetch(`/api/team-stats.php?team_id=${TEAM_ID}`);
  const data = await res.json();
  if(!data.success){ document.querySelector('main').innerHTML='<div class="empty-state">Erro ao carregar dados.</div>'; return; }

  const { team, seasons, playoffs, regular, picks, trades, players, awards, gm } = data;

  document.getElementById('hero-owner').textContent = team.owner_name ? `GM: ${team.owner_name}` : '';

  // ── Stats gerais ──
  const playoffPct = seasons.played > 0 ? Math.round((playoffs.appearances/seasons.played)*100) : 0;
  document.getElementById('stats-grid').innerHTML = [
    statCard('Temporadas',    seasons.played, ''),
    statCard('Total de Pts',  seasons.total_points, `Melhor: ${seasons.best_pts} pts`, true),
    statCard('Playoffs',      playoffs.appearances, `${playoffPct}% das temp.`),
    statCard('Títulos',       playoffs.titles, playoffs.runner_ups>0?`${playoffs.runner_ups}× vice`:'', true),
    statCard('Trocas',        trades, ''),
    statCard('Picks R1 Próprias', picks.own_r1, `${picks.own_r2} de 2ª rodada`),
    statCard('Jogadores',     players.total_ever, 'passaram pelo clube'),
    statCard('FA Contratados',gm.fa_pickups, '', false, true),
  ].join('');

  // ── Fases ──
  const totalSeasons = seasons.played || 1;
  const phases = [
    { icon:'🏆', label:'Títulos',           val: playoffs.titles,       color:'var(--amber)', bold:true },
    { icon:'🥈', label:'Vices',             val: playoffs.runner_ups,   color:'#94a3b8' },
    { icon:'🔥', label:'Final de Conf.',    val: playoffs.conf_finals,  color:'var(--red)' },
    { icon:'⚡', label:'2ª Rodada',         val: playoffs.second_round, color:'var(--text)' },
    { icon:'🎯', label:'1ª Rodada',         val: playoffs.first_round,  color:'var(--text)' },
    { icon:'📊', label:'Regular (Top 8)',   val: regular.top8,          color:'var(--text-2)' },
    { icon:'🔝', label:'Regular (Top 4)',   val: regular.top4,          color:'var(--text-2)' },
    { icon:'1️⃣',  label:'Regular (1° lugar)',val: regular.top1,         color:'var(--green)', bold:true },
  ];
  const phaseRows = phases.map(p => phaseRow(p.icon, p.label, p.val, p.color, p.bold)).join('');
  document.getElementById('phases-panel').innerHTML = phaseRows || '<div class="empty-state">Nenhum dado de fases ainda</div>';

  // ── Destaque jogadores ──
  const pc = document.getElementById('players-content');
  let pHTML = '';
  if(players.best) pHTML += `
    <div class="pl-row">
      <div><div style="font-size:13px;font-weight:600">🏆 ${esc(players.best.player_name)}</div>
      <div style="font-size:11px;color:var(--text-2)">${esc(players.best.position)} · ${players.best.year??''}</div></div>
      <span style="font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;color:var(--green)">${players.best.ovr} OVR</span>
    </div>`;
  if(players.worst) pHTML += `
    <div class="pl-row">
      <div><div style="font-size:13px;font-weight:600">📉 ${esc(players.worst.player_name)}</div>
      <div style="font-size:11px;color:var(--text-2)">${esc(players.worst.position)} · ${players.worst.year??''}</div></div>
      <span style="font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;color:#ef4444">${players.worst.ovr} OVR</span>
    </div>`;
  pc.innerHTML = pHTML || '<div class="empty-state">Nenhum dado ainda</div>';

  // ── GM Stats ──
  const gmRows = [
    { icon:'🔄', label:'Trocas realizadas',      val: trades },
    { icon:'🆓', label:'Jogadores via FA',        val: gm.fa_pickups },
    { icon:'📋', label:'Draftados (histórico)',   val: gm.drafted_players },
    { icon:'👋', label:'Tapas disponíveis',       val: gm.tapas },
    { icon:'👋', label:'Tapas usados',            val: gm.tapas_used },
    { icon:'💰', label:'FBA Points (GM)',         val: gm.fba_points },
    { icon:'🔑', label:'Logins',                  val: gm.logins },
  ].filter(r => r.val > 0);
  document.getElementById('gm-content').innerHTML = gmRows.length
    ? gmRows.map(r => `<div class="pl-row"><span style="font-size:13px">${r.icon} ${r.label}</span><span style="font-family:'Oswald',sans-serif;font-size:18px;font-weight:700">${r.val}</span></div>`).join('')
    : '<div class="empty-state">Nenhum dado do GM ainda</div>';

  // ── Melhor por posição ──
  const posLabels = { PG:'Armador', SG:'Ala-Armador', SF:'Ala', PF:'Ala-Pivô', C:'Pivô' };
  const bpRows = ['PG','SG','SF','PF','C'].filter(p => players.best_by_pos[p]).map(pos => {
    const p = players.best_by_pos[pos];
    return `<div class="pos-row">
      <div class="pos-badge">${pos}</div>
      <div style="flex:1"><div class="pos-name">${esc(p.player_name)}</div>
      <div style="font-size:11px;color:var(--text-3)">${posLabels[pos]} · ${p.year??''}</div></div>
      <div class="pos-ovr">${p.ovr}</div>
    </div>`;
  }).join('');
  document.getElementById('best-by-pos').innerHTML = bpRows || '<div class="empty-state">Nenhum dado ainda</div>';

  // ── Evolução por ano ──
  const ay = players.avg_by_year;
  document.getElementById('avg-by-year').innerHTML = ay.length
    ? `<div style="overflow-x:auto"><table class="year-table">
        <thead><tr><th>Ano</th><th>Jogadores</th><th>OVR Médio</th><th>Idade Média</th></tr></thead>
        <tbody>${ay.map(r=>`<tr>
          <td style="font-weight:700;color:var(--text)">${r.year}</td>
          <td style="color:var(--text-2)">${r.players}</td>
          <td><span style="font-family:'Oswald',sans-serif;font-size:15px;font-weight:700;color:var(--green)">${r.avg_ovr}</span></td>
          <td><span style="font-family:'Oswald',sans-serif;font-size:15px;font-weight:700;color:var(--amber)">${r.avg_age}</span></td>
        </tr>`).join('')}</tbody>
      </table></div>`
    : '<div class="empty-state">Nenhum histórico de temporadas ainda</div>';

  // ── Prêmios ──
  document.getElementById('awards-content').innerHTML = awards.length
    ? awards.map(a=>`<div class="award-row"><span style="font-size:13px;font-weight:600">🏅 ${AWARD_LABELS[a.award_type]||a.award_type}</span><span style="font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;color:var(--amber)">${a.total}×</span></div>`).join('')
    : '<div class="empty-state">Nenhum prêmio individual ainda</div>';

  // ── Elenco campeão ──
  if(playoffs.champ_seasons.length>0 && playoffs.champ_roster.length>0){
    document.getElementById('champ-panel').style.display='block';
    document.getElementById('champ-year').textContent = playoffs.champ_seasons[0].year ?? playoffs.champ_seasons[0].season_number;
    document.getElementById('champ-roster').innerHTML = playoffs.champ_roster.map(p=>`
      <div class="champ-row">
        <div style="width:32px;font-size:10px;font-weight:700;color:var(--text-3)">${esc(p.position)}</div>
        <div style="flex:1;font-size:13px;font-weight:600">${esc(p.player_name)}</div>
        <span class="ovr-pill">${p.ovr} OVR</span>
        <span style="font-size:11px;color:var(--text-3);margin-left:8px">${p.age?p.age+'y':''}</span>
      </div>`).join('');
  }
}

load();
</script>
</body>
</html>
