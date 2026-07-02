<?php
require_once dirname(__DIR__) . '/helpers.php';
$gmId = League::gmTeam();
render_header('Escalação');
if (!$gmId) {
    echo '<p class="muted">Escolha uma franquia no <a href="' . url('gmselect') . '">Modo GM</a>.</p>';
    render_footer(); exit;
}
$t      = League::team($gmId);
$roster = League::roster($gmId);
usort($roster, fn($a,$b) => ($b['ovr'] <=> $a['ovr']));

// Separar titulares e banco
$starters = array_filter($roster, fn($p) => (int)$p['is_starter'] && !(int)($p['injury_games']??0));
$bench    = array_filter($roster, fn($p) => !(int)$p['is_starter'] || (int)($p['injury_games']??0));

// Organizar starters por posição ideal
$posOrder = ['PG','SG','SF','PF','C'];
$byPos = [];
foreach ($starters as $p) {
    $pos = $p['pos'];
    $byPos[$pos][] = $p;
}
// Preenche slots com melhor disponível se vazio
$slots = [];
$used  = [];
foreach ($posOrder as $posSlot) {
    // primeiro tenta jogador exato da posição
    $found = null;
    foreach (($byPos[$posSlot] ?? []) as $p) {
        if (!in_array((int)$p['id'], $used)) { $found = $p; break; }
    }
    // se não, pega qualquer starter não usado
    if (!$found) {
        foreach ($starters as $p) {
            if (!in_array((int)$p['id'], $used)) { $found = $p; break; }
        }
    }
    $slots[$posSlot] = $found;
    if ($found) $used[] = (int)$found['id'];
}

$msg = $_GET['msg'] ?? '';

// Mapas de labels
$posLabel = ['PG'=>'Armador','SG'=>'Ala-Armador','SF'=>'Ala','PF'=>'Ala-Pivô','C'=>'Pivô'];
$posIcon  = ['PG'=>'⬇','SG'=>'↗','SF'=>'↔','PF'=>'↖','C'=>'⬆'];
$moraleLabel = fn($m) => (int)$m >= 85 ? ['🟢','Ótimo'] : ((int)$m >= 65 ? ['🟡','Bom'] : ['🔴','Baixo']);
?>

<?php if ($msg): ?>
<div class="injury-note" style="background:#10371f;border-color:#1f6b3a;color:#9bffc0;margin-bottom:16px"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Team hero mini -->
<div class="team-hero-v2" style="background:linear-gradient(135deg,<?= e($t['primary_color']) ?>,<?= e($t['secondary_color'] ?? $t['primary_color']) ?>99);margin-bottom:20px">
  <?= team_logo($t['abbr'], $t['primary_color'], 'xl', 'th-logo') ?>
  <div class="th-body">
    <h1><?= e(teamFull($t)) ?></h1>
    <p class="th-meta"><?= $t['wins'] ?>-<?= $t['losses'] ?> · Folha <?= money(League::teamPayroll($gmId)) ?> · Química <?= (int)$t['chemistry'] ?></p>
    <p class="th-sub">
      <a href="<?= url('manage') ?>" style="color:rgba(255,255,255,.8);text-decoration:underline">⚙️ Gerenciar →</a>
      &nbsp;·&nbsp;
      <a href="<?= url('trades') ?>" style="color:rgba(255,255,255,.8);text-decoration:underline">⇄ Trocas →</a>
    </p>
  </div>
</div>

<!-- ═══════════════ QUINTETO TITULAR ═══════════════ -->
<div class="section-tag-bar">
  <p class="section-tag" style="margin:0">⛹ Quinteto Titular</p>
  <span style="font-size:12px;color:var(--muted)">Clique no jogador para ver ações</span>
</div>

<div class="lineup-court">
  <?php foreach ($posOrder as $posSlot):
    $p = $slots[$posSlot];
    $ovrCls = $p ? ($p['ovr']>=90?'ovr-elite':($p['ovr']>=80?'ovr-star':($p['ovr']>=75?'ovr-good':'ovr-role'))) : '';
    $inj = $p ? (int)($p['injury_games']??0) : 0;
    $mor = $p ? $moraleLabel($p['morale']) : null;
    $gp  = $p ? max(1,(int)($p['gp']??1)) : 1;
    $ppg = $p && $p['s_pts'] ? number_format($p['s_pts']/$gp,1) : '—';
  ?>
  <div class="lineup-slot <?= $p ? 'filled' : 'empty' ?>" data-pid="<?= $p ? $p['id'] : '' ?>"
       onclick="<?= $p ? 'openPlayer('.(int)$p['id'].','.htmlspecialchars(json_encode($p),ENT_QUOTES).')' : '' ?>">
    <div class="ls-pos-badge"><?= $posIcon[$posSlot] ?> <?= $posSlot ?></div>
    <?php if ($p): ?>
      <div class="ls-face">
        <?= player_photo((int)($p['nba_id']??0), $p['name'], $t['primary_color'], 'lg', 'ls-photo', (int)$p['id'], $p['pos']) ?>
        <?php if ($inj): ?><span class="ls-inj-badge">🩹</span><?php endif; ?>
      </div>
      <div class="ls-info">
        <a href="<?= url('player',['id'=>$p['id']]) ?>" class="ls-name" onclick="event.stopPropagation()"><?= e($p['name']) ?></a>
        <span class="ls-pos-name"><?= $posLabel[$posSlot] ?></span>
        <div class="ls-stats-row">
          <span class="ovr <?= $ovrCls ?>"><?= $p['ovr'] ?></span>
          <span class="ls-stat"><?= $ppg ?> PPG</span>
          <span class="ls-stat"><?= $p['age'] ?> anos</span>
        </div>
        <div class="ls-morale" title="Moral: <?= (int)$p['morale'] ?>">
          <?= $mor[0] ?> <span style="font-size:11px;color:var(--muted)"><?= $mor[1] ?> (<?= (int)$p['morale'] ?>)</span>
        </div>
      </div>
      <div class="ls-actions">
        <a class="ls-btn" href="<?= url('home',['action'=>'boost-morale','pid'=>$p['id']]) ?>" title="Conversar" onclick="event.stopPropagation()">💬</a>
        <a class="ls-btn" href="<?= url('home',['action'=>'rest-player','pid'=>$p['id']]) ?>" title="Descansar" onclick="event.stopPropagation()">😴</a>
        <a class="ls-btn" href="<?= url('player',['id'=>$p['id']]) ?>" title="Ver ficha" onclick="event.stopPropagation()">📋</a>
      </div>
    <?php else: ?>
      <div class="ls-empty-msg">Sem titular<br>nesta posição</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- ═══════════════ BANCO DE RESERVAS ═══════════════ -->
<p class="section-tag" style="margin:20px 0 12px">🪑 Banco de Reservas</p>

<div class="bench-grid">
  <?php foreach ($bench as $p):
    $ovrCls = $p['ovr']>=90?'ovr-elite':($p['ovr']>=80?'ovr-star':($p['ovr']>=75?'ovr-good':'ovr-role'));
    $inj    = (int)($p['injury_games']??0);
    $gp     = max(1,(int)($p['gp']??1));
    $ppg    = $p['s_pts'] ? number_format($p['s_pts']/$gp,1) : '—';
    [$morIcon] = $moraleLabel($p['morale']);
  ?>
  <div class="bench-card <?= $inj ? 'bench-injured' : '' ?>">
    <div class="bc-face">
      <?= player_photo((int)($p['nba_id']??0), $p['name'], $t['primary_color'], 'md', 'bc-photo', (int)$p['id'], $p['pos']) ?>
      <?php if ($inj): ?><span class="bc-inj">🩹 <?= $inj ?>j</span><?php endif; ?>
    </div>
    <div class="bc-info">
      <a href="<?= url('player',['id'=>$p['id']]) ?>" class="bc-name"><?= e($p['name']) ?></a>
      <span class="bc-pos"><?= e($p['pos']) ?></span>
      <div class="bc-row">
        <span class="ovr <?= $ovrCls ?>"><?= $p['ovr'] ?></span>
        <span class="bc-ppg"><?= $ppg ?></span>
        <span class="bc-morale"><?= $morIcon ?></span>
      </div>
    </div>
    <div class="bc-btns">
      <a class="bc-btn" href="<?= url('home',['action'=>'boost-morale','pid'=>$p['id']]) ?>" title="Conversar">💬</a>
      <a class="bc-btn" href="<?= url('home',['action'=>'rest-player','pid'=>$p['id']]) ?>" title="Descansar">😴</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ═══════════════ MINUTAGEM ═══════════════ -->
<section class="card" style="margin-top:24px">
  <div class="card-head"><h2>⏱️ Minutagem</h2>
    <span class="pill" id="minPill">— min total</span>
  </div>
  <p class="legend">Distribua os minutos entre os jogadores. Total ideal: <strong>240</strong> (5 × 48).</p>
  <form method="post" action="<?= url('home', ['action' => 'save-rotation']) ?>" id="rotForm2">
    <input type="hidden" name="team" value="<?= $gmId ?>">
    <div class="minutes-grid">
      <?php foreach ($roster as $p):
        $inj  = (int)($p['injury_games']??0);
        $ovrC = $p['ovr']>=90?'ovr-elite':($p['ovr']>=80?'ovr-star':($p['ovr']>=75?'ovr-good':'ovr-role'));
        $mins = $inj ? 0 : (int)($p['min_target']??0);
      ?>
      <div class="min-row <?= $inj ? 'min-injured' : '' ?>">
        <?= player_photo((int)($p['nba_id']??0), $p['name'], $t['primary_color'], 'sm', 'min-face', (int)$p['id'], $p['pos']) ?>
        <div class="min-info">
          <span class="min-name"><?= e($p['name']) ?></span>
          <span class="min-pos"><?= e($p['pos']) ?> · <?= $p['ovr'] ?> OVR</span>
        </div>
        <div class="min-ctrl">
          <input type="checkbox" name="starter[]" value="<?= $p['id'] ?>" <?= $p['is_starter']?'checked':'' ?> <?= $inj?'disabled':'' ?> class="min-starter" title="Titular">
          <input type="number" name="min[<?= $p['id'] ?>]" value="<?= $mins ?>"
                 min="0" max="48" class="min-input" <?= $inj?'disabled':'' ?>>
          <div class="min-bar-wrap"><div class="min-bar-fill" style="width:<?= $mins/48*100 ?>%;background:<?= e($t['primary_color']) ?>"></div></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary" type="submit" style="margin-top:14px">💾 Salvar minutagem</button>
  </form>
</section>

<!-- ═══════════════ SCOUTING DO PRÓXIMO JOGO ═══════════════ -->
<?php
$upcoming = League::upcomingGames($gmId, 1);
if ($upcoming):
  $next = $upcoming[0];
?>
<section class="card" style="margin-top:20px">
  <div class="card-head"><h2>🔍 Próximo adversário</h2></div>
  <p class="muted" style="margin:0 0 10px">
    <?= $next['is_home'] ? 'vs' : '@' ?>
    <strong><?= e($next['opp_city'].' '.$next['opp_name']) ?></strong>
    · 📅 <?= e(League::dateLabel((int)$next['day'])) ?>
  </p>
  <?php render_scout_card(League::scoutReport((int)$next['opp_id'], $gmId)); ?>
</section>
<?php endif; ?>

<!-- Modal de ações (placeholder visual) -->
<div id="playerModal" class="player-modal" style="display:none" onclick="if(event.target===this)this.style.display='none'">
  <div class="pm-box">
    <button class="pm-close" onclick="document.getElementById('playerModal').style.display='none'">✕</button>
    <div id="pmContent"></div>
  </div>
</div>

<script>
(function(){
  // Minutagem: recalc pill
  const form = document.getElementById('rotForm2');
  const pill = document.getElementById('minPill');
  function recalc(){
    let t=0;
    form.querySelectorAll('.min-input').forEach(i=>{ t += parseInt(i.value||0,10); });
    const ok = t === 240;
    pill.textContent = t + ' min total';
    pill.style.background = ok ? 'rgba(43,212,122,.2)' : 'rgba(245,166,35,.15)';
    pill.style.color = ok ? '#2bd47a' : '#f5a623';
    pill.style.border = 'none';
    // update bars
    form.querySelectorAll('.min-row').forEach(row => {
      const inp = row.querySelector('.min-input');
      const bar = row.querySelector('.min-bar-fill');
      if(inp && bar) bar.style.width = (parseInt(inp.value||0)/48*100) + '%';
    });
  }
  if(form){ form.addEventListener('input', recalc); recalc(); }

  // Modal de ações do jogador
  window.openPlayer = function(pid, p) {
    const modal = document.getElementById('playerModal');
    const content = document.getElementById('pmContent');
    const appBase  = '<?= defined("APP_BASE") ? APP_BASE : "" ?>';
    const faceUrl = appBase+'/face.php?id='+pid+'&name='+encodeURIComponent(p.name)+'&pos='+(p.pos||'');
    const cdnUrl  = p.nba_id > 0 ? `https://cdn.nba.com/headshots/nba/latest/260x190/${p.nba_id}.png` : null;
    const photoSrc = cdnUrl || faceUrl;
    const photoErr = cdnUrl ? `this.onerror=null;this.src='${faceUrl}'` : `this.src='${appBase}/face.php?id=1&name=Player'`;
    content.innerHTML = `
      <div class="pm-hero">
        <div class="pm-photo-wrap" style="background:linear-gradient(180deg,rgba(228,0,43,.15),rgba(0,0,0,.4));border-radius:10px;padding:8px;display:flex;align-items:flex-end;justify-content:center;min-height:100px">
          <img src="${photoSrc}" class="pm-face" onerror="${photoErr}" style="border-radius:8px">
        </div>
        <div class="pm-heroinfo">
          <div class="pm-pname">${p.name}</div>
          <div class="pm-pmeta">${p.pos} · ${p.age} anos · ${p.ovr} OVR</div>
          <div class="pm-morale">Moral: ${p.morale}</div>
        </div>
      </div>
      <div class="pm-attrs">
        ${attrBar('🏀 Ataque',   Math.round((+p.ins + +p.mid + +p.thr + +p.pmk)/4))}
        ${attrBar('🛡 Defesa',   p.def)}
        ${attrBar('💪 Atletismo',p.ath)}
        ${attrBar('⚡ Stamina',  p.sta)}
      </div>
      <div class="pm-actions">
        <a class="pm-btn" href="${appBase}/index.php?action=boost-morale&pid=${pid}">💬 Conversar</a>
        <a class="pm-btn" href="${appBase}/index.php?action=rest-player&pid=${pid}">😴 Descansar</a>
        <a class="pm-btn pm-view" href="${appBase}/index.php?p=player&id=${pid}">📋 Ver ficha completa</a>
      </div>
    `;
    modal.style.display = 'flex';
  };

  function attrBar(label, val){
    val = Math.min(99, Math.max(0, parseInt(val)||0));
    const cls = val>=80?'#E4002B':val>=65?'#f5a623':'#4a5568';
    return `<div class="pm-attr">
      <span class="pm-al">${label}</span>
      <div class="pm-abar"><div style="width:${val}%;background:${cls}"></div></div>
      <span class="pm-av">${val}</span>
    </div>`;
  }
})();
</script>

<?php render_footer(); ?>
