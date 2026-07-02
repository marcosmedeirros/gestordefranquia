<?php
require_once dirname(__DIR__) . '/helpers.php';
$gmId = League::gmTeam();
render_header('Meu Time');
if (!$gmId) {
    echo '<p class="muted">Você ainda não escolheu uma franquia. <a href="' . url('gmselect') . '">Escolher agora →</a></p>';
    render_footer(); exit;
}
$t = League::team($gmId);
$roster = League::roster($gmId);
// ordena: rotação primeiro
usort($roster, function ($a, $b) {
    if (($b['rotation'] ?? 0) !== ($a['rotation'] ?? 0)) return ($b['rotation'] ?? 0) <=> ($a['rotation'] ?? 0);
    return $b['ovr'] <=> $a['ovr'];
});
$warns = isset($_GET['w']) && $_GET['w'] !== '' ? explode('|', $_GET['w']) : [];
?>
<div class="team-hero-v2" style="background:linear-gradient(135deg,<?= e($t['primary_color']) ?>,<?= e($t['secondary_color'] ?? $t['primary_color']) ?>99)">
  <?= team_logo($t['abbr'], $t['primary_color'], 'xl', 'th-logo') ?>
  <div class="th-body">
    <h1><?= e(teamFull($t)) ?></h1>
    <p class="th-meta">
      <?= $t['conf']==='E'?'Conferência Leste':'Conferência Oeste' ?> · <?= e($t['div']) ?>
      · <strong style="font-size:18px"><?= $t['wins'] ?>-<?= $t['losses'] ?></strong>
      · Folha <strong><?= money(League::teamPayroll($gmId)) ?></strong>
      · Química <strong><?= (int)$t['chemistry'] ?></strong>
    </p>
    <p class="th-sub">
      <a href="<?= url('gmselect') ?>" style="color:rgba(255,255,255,.8);text-decoration:underline">Trocar de franquia</a>
      &nbsp;·&nbsp;
      <a href="<?= url('trades') ?>" style="color:rgba(255,255,255,.8);text-decoration:underline">Central de Trocas →</a>
    </p>
  </div>
</div>

<?php $goal = League::boardGoalProgress(); if ($goal): ?>
<div class="board-goal goal-<?= $goal['status'] ?>">
  🎯 <strong>Meta da diretoria:</strong> <?= e($goal['desc']) ?>
  <span class="goal-detail"><?= e($goal['detail']) ?></span>
  <span class="goal-badge"><?= ['andamento'=>'em andamento','cumprida'=>'✅ cumprida','falhou'=>'❌ não cumprida'][$goal['status']] ?></span>
</div>
<?php endif; ?>

<?php
$coach = League::gmCoach();
if (isset($_GET['saved'])): ?>
  <div class="injury-note" style="background:#10371f;border-color:#1f6b3a;color:#9bffc0">
    ✅ <?= $_GET['saved']==='scheme' ? 'Estilo de jogo salvo.' : ($_GET['saved']==='coach' ? 'Técnico atualizado.' : 'Rotação salva.') ?>
    <?php foreach ($warns as $w): ?><br>⚠️ <?= e($w) ?><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($coach): ?>
<section class="card coach-card">
  <div class="card-head">
    <h2>🎽 Técnico</h2>
    <span class="pill" style="background:rgba(228,0,43,.15);color:#E4002B;border:1px solid rgba(228,0,43,.3)">
      <?= e(ucfirst($coach['style'])) ?>
    </span>
  </div>
  <form method="post" action="<?= url('home', ['action'=>'save-coach']) ?>" class="coach-form">
    <div class="coach-identity">
      <div class="coach-avatar"><?= strtoupper(substr($coach['name'],0,1)) ?></div>
      <div>
        <input type="text" name="coach_name" value="<?= e($coach['name']) ?>" class="coach-name-input" maxlength="40">
        <div class="coach-record"><?= (int)$coach['wins'] ?>V · <?= (int)$coach['losses'] ?>D · <?= (int)$coach['seasons'] ?> temp.</div>
      </div>
    </div>
    <div class="coach-attrs">
      <?php
      $attrLabels = [
        'ofensivo'        => ['🏀','Ofensivo',    'Mais pontos, pace rápido, mais bolas de 3'],
        'defensivo'       => ['🛡️','Defensivo',   'Menos pontos cedidos, mais roubos e bloqueios'],
        'desenvolvimento' => ['📈','Desenvolvimento','Jovens crescem mais rápido no seu elenco'],
        'gestao'          => ['🤝','Gestão',      'Melhor química, moral e confiança da diretoria'],
        'intensidade'     => ['🔥','Intensidade', 'Mais energia, rebotes e luta, porém mais lesões'],
      ];
      foreach ($attrLabels as $key => [$icon, $label, $tip]):
        $val = (int)$coach[$key];
        $pct = $val;
        $cls = $val>=80?'attr-elite':($val>=65?'attr-good':'attr-low');
      ?>
      <div class="coach-attr-row">
        <span class="car-icon"><?= $icon ?></span>
        <span class="car-label" title="<?= e($tip) ?>"><?= $label ?></span>
        <div class="car-bar"><div class="car-fill <?= $cls ?>" style="width:<?= $pct ?>%"></div></div>
        <input type="number" name="<?= $key ?>" value="<?= $val ?>" min="1" max="99" class="car-num">
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary" type="submit" style="margin-top:10px">💾 Salvar técnico</button>
  </form>
</section>
<?php endif; ?>

<p class="section-tag">⚙️ Comando da franquia — defina o estilo de jogo e os minutos do elenco</p>

<div class="dashboard">
  <section class="card">
    <div class="card-head"><h2>🎯 Estilo de jogo</h2></div>
    <form method="post" action="<?= url('home', ['action' => 'save-scheme']) ?>" class="scheme-form">
      <input type="hidden" name="team" value="<?= $gmId ?>">
      <label>Ataque
        <select name="scheme_off">
          <?php foreach (League::SCHEMES_OFF as $s): ?>
            <option value="<?= e($s) ?>" <?= $t['scheme_off']===$s?'selected':'' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Defesa
        <select name="scheme_def">
          <?php foreach (League::SCHEMES_DEF as $s): ?>
            <option value="<?= e($s) ?>" <?= $t['scheme_def']===$s?'selected':'' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn btn-primary" type="submit">Salvar estilo</button>
    </form>
    <p class="legend" style="margin-top:12px">
      <strong>Pace and Space</strong>: mais bolas de 3 · <strong>Pick and Roll</strong>: mais assistências ·
      <strong>Post Play</strong>: jogo interior, mais lento · <strong>2-3 Zone</strong>: fecha o garrafão (cede 3pts) ·
      <strong>Switch All</strong>: corta as assistências adversárias.
    </p>
  </section>

  <section class="card">
    <div class="card-head"><h2>⏱️ Estilo no jogo ao vivo</h2></div>
    <?php
      $gmGameToday = null;
      foreach (League::gamesByDay(League::currentDay()) as $gg) {
        if (((int)$gg['home_id'] === $gmId || (int)$gg['away_id'] === $gmId) && !$gg['played']) { $gmGameToday = $gg; break; }
      }
    ?>
    <p class="legend">O estilo acima é o padrão da equipe. Durante o jogo ao vivo você pode mudar a tática
       quarto a quarto, fazer marcação dupla e pedir tempo.</p>
    <?php if ($gmGameToday): ?>
      <a class="btn btn-primary" href="<?= url('game', ['id'=>$gmGameToday['id'], 'live'=>1]) ?>">🎮 Comandar jogo de hoje (<?= e($gmGameToday['away_abbr']) ?> @ <?= e($gmGameToday['home_abbr']) ?>)</a>
    <?php else: ?>
      <p class="muted">Nenhum jogo seu pendente hoje. Avance a data no <a href="<?= url('home') ?>">Início</a>.</p>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <div class="card-head"><h2>🔁 Rotação & Minutagem</h2></div>
  <p class="legend">Marque os <strong>5 titulares</strong> e distribua os minutos (total ideal: <strong>240</strong> = 5×48).
     Jogador com 0 minutos fica fora da rotação. <span id="minSum"></span></p>
  <form method="post" action="<?= url('home', ['action' => 'save-rotation']) ?>" id="rotForm">
    <input type="hidden" name="team" value="<?= $gmId ?>">
    <div class="roster-header">
      <span></span><span>Jogador</span><span style="text-align:center">OVR</span>
      <span class="num">PPG</span><span class="num">Idade</span><span class="num">Est.</span>
      <span style="text-align:right">MIN</span>
    </div>
    <div class="roster-grid">
      <?php $idx = 1; foreach ($roster as $p):
        $inj   = (int)($p['injury_games'] ?? 0);
        $ovrc  = $p['ovr']>=90?'ovr-elite':($p['ovr']>=80?'ovr-star':($p['ovr']>=75?'ovr-good':'ovr-role'));
        $gp    = max(1,(int)($p['gp']??1));
        $ppg   = $p['s_pts'] ? number_format($p['s_pts']/$gp,1) : '—';
        $role  = $p['is_starter'] ? 'starter' : 'bench';
      ?>
      <div class="roster-row <?= $role ?>">
        <div class="rr-num">
          <input type="checkbox" name="starter[]" value="<?= $p['id'] ?>"
                 <?= $p['is_starter']?'checked':'' ?> <?= $inj?'disabled':'' ?>
                 title="<?= $p['is_starter']?'Titular':'Reserva' ?>">
        </div>
        <div class="rr-name" style="display:flex;align-items:center;gap:8px">
          <?= player_photo((int)($p['nba_id']??0), $p['name'], $t['primary_color'], 'sm', 'rr-face', (int)$p['id'], $p['pos']) ?>
          <div>
            <a href="<?= url('player',['id'=>$p['id']]) ?>"><?= e($p['name']) ?></a>
            <span class="rr-pos"><?= e($p['pos']) ?></span>
            <?php if ($inj): ?><span class="badge-inj">🩹 <?= $inj ?>j</span><?php endif; ?>
          </div>
        </div>
        <div class="rr-ovr"><span class="ovr <?= $ovrc ?>"><?= $p['ovr'] ?></span></div>
        <div class="rr-stat num"><strong><?= $ppg ?></strong></div>
        <div class="rr-stat num"><?= $p['age'] ?></div>
        <div class="rr-stat num"><?= (int)$p['sta'] ?></div>
        <div class="rr-mins">
          <input class="min-input" type="number" min="0" max="48" name="min[<?= $p['id'] ?>]"
                 value="<?= $inj ? 0 : (int)($p['min_target'] ?? 0) ?>" <?= $inj?'disabled':'' ?>>
        </div>
      </div>
      <?php $idx++; endforeach; ?>
    </div>
    <div style="margin-top:14px"><button class="btn btn-primary" type="submit">💾 Salvar rotação</button></div>
  </form>
</section>

<?php render_decisions(League::pendingDecisions(), url('manage')); ?>

<?php $oc = League::ownerConfidence(); $inbox = League::gmInbox(6); ?>
<div class="dashboard">
  <section class="card">
    <div class="card-head"><h2>📥 Inbox do GM</h2>
      <?php if ($oc): ?><span class="pill conf-<?= $oc['value']>=45?'ok':($oc['value']>=30?'warn':'bad') ?>">Diretoria: <?= e($oc['label']) ?> (<?= $oc['value'] ?>%)</span><?php endif; ?>
    </div>
    <?php if ($oc): ?><div class="conf-bar"><span style="width:<?= max(2,min(100,$oc['value'])) ?>%"></span></div><?php endif; ?>
    <div class="inbox-list">
      <?php foreach ($inbox as $m): ?>
        <div class="inbox-item"><span class="ib-icon"><?= $m['icon'] ?></span>
          <div><span class="ib-from"><?= e($m['from']) ?></span><?= e($m['text']) ?></div></div>
      <?php endforeach; ?>
      <?php if (!$inbox): ?><p class="muted">Sem mensagens no momento.</p><?php endif; ?>
    </div>
  </section>
  <section class="card">
    <?php
      $upcoming = League::upcomingGames($gmId, 8);
      $nextGame = $upcoming[0] ?? null;
    ?>
    <div class="card-head"><h2>📅 Próximos jogos</h2></div>
    <?php render_team_schedule($upcoming); ?>
  </section>
</div>

<?php if (!empty($nextGame)): ?>
<section class="card">
  <div class="card-head"><h2>🔍 Scouting do próximo adversário</h2></div>
  <p class="muted" style="margin:0 0 10px">Próximo: <?= $nextGame['is_home'] ? 'vs' : '@' ?>
    <strong><?= e($nextGame['opp_city'].' '.$nextGame['opp_name']) ?></strong> · 📅 <?= e(League::dateLabel((int)$nextGame['day'])) ?></p>
  <?php render_scout_card(League::scoutReport((int)$nextGame['opp_id'], $gmId)); ?>
</section>
<?php endif; ?>

<script>
(function(){
  const form = document.getElementById('rotForm');
  const sumEl = document.getElementById('minSum');
  function recalc(){
    let total=0, inRot=0;
    form.querySelectorAll('.min-input').forEach(i=>{ const v=parseInt(i.value||0,10); if(v>0){total+=v;inRot++;} });
    sumEl.innerHTML = 'Total atual: <strong style="color:'+(total===240?'#2bd47a':'#f5a623')+'">'+total+'</strong> min · '+inRot+' na rotação';
  }
  form.addEventListener('input', recalc); recalc();
})();
</script>
<?php render_footer(); ?>
