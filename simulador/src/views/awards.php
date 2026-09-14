<?php
require_once dirname(__DIR__) . '/helpers.php';
$season = League::season();
$champId = (int) Database::meta('champion_id', 0);
if (!$champId) { header('Location: ' . url('home')); exit; }
$champ = League::team($champId);
$gmId = League::gmTeam();
$isChamp = $gmId && $gmId === $champId;
$chRow = null;
foreach (League::champions() as $c) { if ((int) $c['season'] === $season) { $chRow = $c; break; } }
$runner = $chRow && $chRow['runnerup_id'] ? League::team((int) $chRow['runnerup_id']) : null;
$aw = League::awards($season);
$byType = [];
foreach ($aw as $a) { $byType[$a['type']][] = $a; }
$main = [
  'MVP' => ['award-fill', 'MVP da temporada', 'O melhor jogador da liga'],
  'Finals MVP' => ['trophy-fill', 'MVP das finais', 'Decisivo na série do título'],
  'DPOY' => ['shield-fill', 'Defensor do ano', 'Tocos, roubos e presença'],
  'ROY' => ['stars', 'Novato do ano', 'O melhor calouro'],
  '6º Homem' => ['lightning-charge-fill', '6º homem do ano', 'O melhor saindo do banco'],
  'MIP' => ['graph-up-arrow', 'Quem mais evoluiu', 'O maior salto de OVR'],
];
$gmAwards = $gmId ? array_values(array_filter($aw, fn($a) => (int) $a['team_id'] === $gmId)) : [];
$myHist = $gmId ? League::teamSeasonHistory($gmId) : [];
$myRow = null; foreach ($myHist as $h) { if ((int) $h['season'] === $season) { $myRow = $h; break; } }
Database::setMeta('awards_seen', (string) $season);

$teamMap = [];
foreach (League::allTeams() as $t) $teamMap[(int) $t['id']] = $t;
$typeLabel = fn(string $type): string => $main[$type][1]
    ?? (preg_match('/^All-NBA (\d)$/', $type, $m) ? $m[1] . 'º time All-NBA' : $type);
$allNba = array_values(array_filter([1, 2, 3], fn($n) => !empty($byType["All-NBA $n"])));
$nMain = count(array_filter(array_keys($main), fn($type) => !empty($byType[$type]))); // a grade se ajusta a quantos prêmios saíram

render_header('Premiação');
?>
<div class="stack">
  <section class="aw-stage<?= $isChamp ? ' is-champ' : '' ?>">
    <?php if ($isChamp): ?><canvas id="confetti" class="aw-confetti" aria-hidden="true" data-c1="<?= e($champ['primary_color'] ?? '') ?>" data-c2="<?= e($champ['secondary_color'] ?? '') ?>"></canvas><?php endif; ?>
    <div class="aw-in">
      <span class="eyebrow">Temporada <?= $season ?> · Cerimônia de premiação</span>
      <span class="aw-trophy" aria-hidden="true"><?= bi('trophy-fill') ?></span>
      <?= team_logo($champ['abbr'], $champ['primary_color'] ?? '#333', 'hero') ?>
      <span class="aw-lbl"><?= $isChamp ? 'Campeão! A taça é sua' : 'Campeão da liga' ?></span>
      <h1><?= e(teamFull($champ)) ?></h1>
      <?php if ($runner): ?>
        <p class="aw-runner">Venceu as finais contra <?= team_logo($runner['abbr'], $runner['primary_color'] ?? '#333', 'sm') ?><b><?= e(teamFull($runner)) ?></b></p>
      <?php endif; ?>
      <?php if ($isChamp): ?><p class="aw-you">A sua franquia levantou o troféu. A diretoria, a torcida e o vestiário estão em festa. O próximo desafio é repetir.</p><?php endif; ?>
      <div class="aw-links">
        <a class="btn btn-ghost" href="<?= url('playoffs') ?>"><?= bi('diagram-3-fill') ?>Chaveamento dos playoffs</a>
        <a class="btn btn-ghost" href="<?= url('history') ?>"><?= bi('clock-history') ?>Histórico da liga</a>
      </div>
    </div>
  </section>

  <div class="aw-grid n<?= $nMain ?>">
    <?php foreach ($main as $type => [$icon, $label, $sub]): if (empty($byType[$type])) continue;
      $a = $byType[$type][0];
      $mine = $gmId && (int) $a['team_id'] === $gmId;
      $pl = League::player((int) $a['player_id']);
      $at = $teamMap[(int) $a['team_id']] ?? null;
      $val = $a['value'] && $a['value'] !== $a['player_name'] ? (string) $a['value'] : ''; ?>
      <a class="aw-card<?= $type === 'MVP' ? ' is-mvp' : '' ?><?= $mine ? ' mine' : '' ?>" href="<?= url('player', ['id' => $a['player_id']]) ?>">
        <div class="awc-head">
          <span class="awc-ic"><?= bi($icon) ?></span>
          <span class="awc-lbl"><b><?= e($label) ?></b><small><?= e($sub) ?></small></span>
        </div>
        <div class="awc-body">
          <?= player_photo((int) ($pl['nba_id'] ?? 0), (string) $a['player_name'], (string) ($at['primary_color'] ?? '#1a1a2e'), 'md', 'awc-face', (int) $a['player_id'], (string) ($a['pos'] ?? '')) ?>
          <div class="awc-who">
            <b><?= e($a['player_name']) ?></b>
            <span class="awc-team"><?= team_logo((string) $a['abbr'], $at['primary_color'] ?? '#333', 'sm') ?><?= e($a['abbr']) ?> · <?= e($a['pos']) ?></span>
          </div>
        </div>
        <?php if ($val !== '' || $mine): ?>
        <div class="awc-foot">
          <?php if ($val !== ''): ?><span class="awc-val"><?= e($val) ?></span><?php endif; ?>
          <?php if ($mine): ?><?= chip('Seu elenco', 'team', 'person-fill') ?><?php endif; ?>
        </div>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($allNba): ?>
  <div class="grid cols-3">
    <?php foreach ($allNba as $n): ?>
    <section class="panel">
      <?= panel_head($n . 'º time All-NBA', ['icon' => 'star-fill']) ?>
      <div class="aw-list">
        <?php foreach ($byType["All-NBA $n"] as $a): $at = $teamMap[(int) $a['team_id']] ?? null; $mine = $gmId && (int) $a['team_id'] === $gmId; ?>
          <a class="aw-line<?= $mine ? ' mine' : '' ?>" href="<?= url('player', ['id' => $a['player_id']]) ?>">
            <span class="pos-tag"><?= e($a['pos']) ?></span>
            <span class="who"><?= team_logo((string) $a['abbr'], $at['primary_color'] ?? '#333', 'sm') ?><span class="w-txt"><b><?= e($a['player_name']) ?></b><small><?= e($a['abbr']) ?><?= $a['value'] ? ' · ' . e($a['value']) : '' ?></small></span></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($gmId): ?>
  <section class="panel hot">
    <?= panel_head('Sua temporada', ['icon' => 'person-badge-fill', 'more' => ['Ver o time', url('team', ['id' => $gmId])]]) ?>
    <div class="stats">
      <?php if ($myRow):
        [, $exitTxt] = split_title_icon(League::exitLabel((int) $myRow['exit_round'], (int) $myRow['made_playoffs'])); ?>
        <?= stat_tile('Campanha', (int) $myRow['wins'] . '-' . (int) $myRow['losses'], ((int) $myRow['seed'] ? (int) $myRow['seed'] . 'º na conferência · ' : '') . $exitTxt) ?>
      <?php endif; ?>
      <?= stat_tile('Seus prêmios', (string) count($gmAwards), $gmAwards ? (count($gmAwards) === 1 ? 'prêmio individual' : 'prêmios individuais') : 'ninguém premiado', $gmAwards ? 'pos' : '') ?>
    </div>
    <?php if ($gmAwards): ?>
      <div class="sub-h">Prêmios do seu elenco</div>
      <div class="row aw-mine">
        <?php foreach ($gmAwards as $a): ?><?= chip($typeLabel((string) $a['type']) . ' · ' . $a['player_name'], 'team', 'award-fill') ?><?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted aw-none">Nenhum jogador seu foi premiado nesta temporada.</p>
    <?php endif; ?>
    <div class="aw-bonus"><?= note('Os bônus de prêmio entram na folha dos premiados na próxima temporada: MVP +5M, defensor do ano +3M, MVP das finais +3M, novato do ano +2M, 6º homem +2M, quem mais evoluiu +2M e All-NBA +3M, +2M ou +1M.', 'info', 'cash-coin') ?></div>
  </section>
  <?php endif; ?>
</div>

<?php if ($isChamp): ?>
<script>
(function(){
  const c = document.getElementById('confetti'); if (!c) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const ctx = c.getContext('2d');
  const box = c.parentElement;
  function size(){ c.width = box.clientWidth; c.height = box.clientHeight; }
  size(); window.addEventListener('resize', size);
  const go = getComputedStyle(document.documentElement).getPropertyValue('--go').trim();
  const colors = [c.dataset.c1 || '#E4002B', go || '#ffb81c', '#ffffff', c.dataset.c2 || '#333333'];
  const P = []; for (let i = 0; i < 140; i++) P.push({x: Math.random()*c.width, y: Math.random()*-c.height, w: 6+Math.random()*6, h: 8+Math.random()*8, v: 1.2+Math.random()*2.2, a: Math.random()*Math.PI, s: (Math.random()-.5)*.1, col: colors[i%colors.length]});
  const t0 = performance.now();
  function frame(t){
    ctx.clearRect(0,0,c.width,c.height);
    P.forEach(p => { p.y += p.v; p.a += p.s; p.x += Math.sin(p.a)*.6; if (p.y > c.height + 20) { p.y = -20; p.x = Math.random()*c.width; }
      ctx.save(); ctx.translate(p.x,p.y); ctx.rotate(p.a); ctx.fillStyle = p.col; ctx.fillRect(-p.w/2,-p.h/2,p.w,p.h); ctx.restore(); });
    if (t - t0 < 14000) requestAnimationFrame(frame); else ctx.clearRect(0,0,c.width,c.height);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
