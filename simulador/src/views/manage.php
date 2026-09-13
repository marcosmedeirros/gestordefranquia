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
$capM = Cap::summary($gmId);
$goal = League::boardGoalProgress();
$oc = League::ownerConfidence();
$pat = League::boardPatience();
$coach = League::gmCoach();
$block = League::tradeBlock();
$focus = array_values(array_filter($roster, fn($p) => !empty($p['dev_focus'])));
?>
<div class="team-hero-v2" style="background:linear-gradient(135deg,<?= e($t['primary_color']) ?>,<?= e($t['secondary_color'] ?? $t['primary_color']) ?>99)">
  <?= team_logo($t['abbr'], $t['primary_color'], 'xl', 'th-logo') ?>
  <div class="th-body">
    <h1><?= e(teamFull($t)) ?></h1>
    <p class="th-meta">
      <?= $t['conf']==='E'?'Conferência Leste':'Conferência Oeste' ?> · <?= e($t['div']) ?>
      · <strong style="font-size:18px"><?= $t['wins'] ?>-<?= $t['losses'] ?></strong>
      · Folha <strong><?= Cap::m($capM['payroll']) ?></strong> <span style="opacity:.8">/ teto <?= Cap::m($capM['cap_max']) ?></span><?= $capM['status'] !== 'ok' ? ' <span class="neg-txt">(' . ($capM['status'] === 'over' ? 'acima do teto' : 'abaixo do piso') . ')</span>' : '' ?>
      · Química <strong><?= (int)$t['chemistry'] ?></strong>
    </p>
    <p class="th-sub">
      <a href="<?= url('lineup') ?>" style="color:rgba(255,255,255,.8);text-decoration:underline">Escalação →</a>
      &nbsp;·&nbsp;
      <a href="<?= url('trades') ?>" style="color:rgba(255,255,255,.8);text-decoration:underline">Central de Trocas →</a>
      &nbsp;·&nbsp;
      <a href="<?= url('cap') ?>" style="color:rgba(255,255,255,.8);text-decoration:underline">Folha &amp; Cap →</a>
    </p>
  </div>
</div>

<?php if (isset($_GET['saved'])): ?>
  <div class="injury-note" style="background:#10371f;border-color:#1f6b3a;color:#9bffc0">
    ✅ <?= $_GET['saved']==='scheme' ? 'Estilo de jogo salvo.' : ($_GET['saved']==='coach' ? 'Técnico atualizado.' : 'Rotação salva.') ?>
    <?php foreach ($warns as $w): ?><br>⚠️ <?= e($w) ?><?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ═══════════ DIRETORIA ═══════════ -->
<section class="card">
  <div class="card-head"><h2>🏛️ Diretoria</h2>
    <?php if ($oc): ?><span class="pill conf-<?= $oc['value']>=45?'ok':($oc['value']>=30?'warn':'bad') ?>">Confiança: <?= e($oc['label']) ?> (<?= $oc['value'] ?>%)</span><?php endif; ?>
  </div>
  <div class="board-panel">
    <div class="board-box">
      <div class="bb-lbl">🎯 Meta da temporada</div>
      <div class="bb-val" style="font-size:16px"><?= $goal ? e($goal['desc']) : '—' ?></div>
      <div class="bb-sub"><?= $goal ? e($goal['detail']) . ' · ' . ['andamento'=>'em andamento','cumprida'=>'✅ cumprida','falhou'=>'❌ não cumprida'][$goal['status']] : '' ?></div>
    </div>
    <div class="board-box">
      <div class="bb-lbl">⏳ Paciência</div>
      <div class="bb-val"><span class="pat-dots"><?php for ($i = 1; $i <= League::PATIENCE_MAX; $i++): ?><i class="<?= $i <= $pat ? 'on' : '' ?>"></i><?php endfor; ?></span></div>
      <div class="bb-sub">Meta cumprida enche, meta perdida gasta (2 se nem o mínimo sair). Zerou: demissão.<?= $pat <= 1 ? ' <strong class="pat-warn">Última chance.</strong>' : '' ?></div>
    </div>
    <div class="board-box">
      <div class="bb-lbl">📈 Confiança do dono</div>
      <div class="bb-val"><?= $oc ? $oc['value'] . '%' : '—' ?></div>
      <?php if ($oc): ?><div class="conf-bar" style="margin-top:6px"><span style="width:<?= max(2,min(100,$oc['value'])) ?>%"></span></div><?php endif; ?>
      <div class="bb-sub">Campanha vs. o que o elenco promete; sequências pesam.</div>
    </div>
    <div class="board-box">
      <div class="bb-lbl">💰 Folha</div>
      <div class="bb-val" style="font-size:18px"><?= Cap::m($capM['payroll']) ?> <small style="font-size:11px;font-weight:600;color:#888">/ <?= Cap::m($capM['cap_max']) ?></small></div>
      <div class="bb-sub"><?= $capM['status'] === 'ok' ? Cap::m($capM['space']) . ' de espaço' : ($capM['status'] === 'over' ? '<span class="neg-txt">' . Cap::m($capM['excess']) . ' acima do teto</span>' : Cap::m($capM['deficit']) . ' abaixo do piso') ?> · deadline dia <?= Cap::deadlineDay() ?></div>
    </div>
  </div>
</section>

<!-- ═══════════ TÉCNICO ═══════════ -->
<?php if ($coach):
  $attrLabels = [
    'ofensivo'        => ['🏀','Ofensivo',        'eficiência do ataque'],
    'defensivo'       => ['🛡️','Defensivo',       'nota da defesa'],
    'desenvolvimento' => ['📈','Desenvolvimento', 'jovens progridem mais na entressafra'],
    'gestao'          => ['🤝','Gestão',          'moral e química'],
    'intensidade'     => ['🔥','Intensidade',     'rebotes, mas mais desgaste e lesões'],
  ];
?>
<section class="card coach-card">
  <div class="card-head"><h2>🎽 Técnico</h2>
    <span class="pill" style="background:rgba(228,0,43,.15);color:#E4002B;border:1px solid rgba(228,0,43,.3)"><?= e(ucfirst($coach['style'])) ?></span>
  </div>
  <div class="coach-static">
    <form method="post" action="<?= url('home', ['action'=>'save-coach']) ?>" class="coach-identity">
      <div class="coach-avatar"><?= strtoupper(substr($coach['name'],0,1)) ?></div>
      <div>
        <input type="text" name="coach_name" value="<?= e($coach['name']) ?>" class="coach-name-input" maxlength="40">
        <div class="coach-record"><?= (int)$coach['wins'] ?>V · <?= (int)$coach['losses'] ?>D · <?= (int)$coach['seasons'] ?> temp.</div>
        <button class="btn btn-sm" type="submit" style="margin-top:6px">Salvar nome</button>
      </div>
    </form>
    <div>
      <div class="coach-attrs">
        <?php foreach ($attrLabels as $key => [$icon, $label, $tip]):
          $val = (int)$coach[$key];
          $cls = $val>=80?'attr-elite':($val>=65?'attr-good':'attr-low'); ?>
          <div class="coach-attr-row">
            <span class="car-icon"><?= $icon ?></span>
            <span class="car-label" title="<?= e($tip) ?>"><?= $label ?></span>
            <div class="car-bar"><div class="car-fill <?= $cls ?>" style="width:<?= $val ?>%"></div></div>
            <span class="car-num"><?= $val ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="coach-effect">Os atributos vêm do estilo escolhido na criação do save e <strong>pesam no jogo</strong>: ofensivo/defensivo na simulação, desenvolvimento na progressão dos seus jovens (junto com o <strong>foco de treino</strong>, na Escalação), intensidade em rebotes e risco de lesão.</div>
    </div>
  </div>
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
    <div class="card-head"><h2>🧭 Projeto</h2></div>
    <p class="legend" style="margin:0 0 8px"><strong>🎯 Foco de treino</strong> (até <?= League::DEV_FOCUS_MAX ?>, até 25 anos): progridem mais na entressafra.</p>
    <div class="cap-actions" style="margin-bottom:10px">
      <?php if ($focus): foreach ($focus as $p): ?><span class="flag-pill focus">🎯 <?= e($p['name']) ?> (<?= (int)$p['ovr'] ?>)</span><?php endforeach; else: ?><span class="muted" style="font-size:12px">ninguém — defina na Escalação</span><?php endif; ?>
    </div>
    <p class="legend" style="margin:0 0 8px"><strong>📣 Vitrine de trocas</strong>: a liga sabe que estão à venda e manda propostas na caixa.</p>
    <div class="cap-actions">
      <?php $inBlock = array_values(array_filter($roster, fn($p) => in_array((int)$p['id'], $block, true)));
      if ($inBlock): foreach ($inBlock as $p): ?><span class="flag-pill block">📣 <?= e($p['name']) ?> (<?= (int)$p['ovr'] ?>)</span><?php endforeach; else: ?><span class="muted" style="font-size:12px">ninguém na vitrine</span><?php endif; ?>
    </div>
    <?php
      $gmGameToday = null;
      foreach (League::gamesByDay(League::currentDay()) as $gg) {
        if (((int)$gg['home_id'] === $gmId || (int)$gg['away_id'] === $gmId) && !$gg['played']) { $gmGameToday = $gg; break; }
      }
      if ($gmGameToday): ?>
      <a class="btn btn-primary" style="margin-top:12px" href="<?= url('game', ['id'=>$gmGameToday['id'], 'live'=>1]) ?>">🎮 Comandar jogo de hoje (<?= e($gmGameToday['away_abbr']) ?> @ <?= e($gmGameToday['home_abbr']) ?>)</a>
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
            <?php if (!empty($p['dev_focus'])): ?><span class="flag-pill focus">🎯</span><?php endif; ?>
            <?php if (in_array((int)$p['id'], $block, true)): ?><span class="flag-pill block">📣</span><?php endif; ?>
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

<?php $upcoming = League::upcomingGames($gmId, 8); $nextGame = $upcoming[0] ?? null; ?>
<div class="dashboard">
  <section class="card">
    <div class="card-head"><h2>📅 Próximos jogos</h2></div>
    <?php render_team_schedule($upcoming); ?>
  </section>
  <?php if (!empty($nextGame)): ?>
  <section class="card">
    <div class="card-head"><h2>🔍 Scouting do próximo adversário</h2></div>
    <p class="muted" style="margin:0 0 10px">Próximo: <?= $nextGame['is_home'] ? 'vs' : '@' ?>
      <strong><?= e($nextGame['opp_city'].' '.$nextGame['opp_name']) ?></strong> · 📅 <?= e(League::dateLabel((int)$nextGame['day'])) ?></p>
    <?php render_scout_card(League::scoutReport((int)$nextGame['opp_id'], $gmId)); ?>
  </section>
  <?php endif; ?>
</div>

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
