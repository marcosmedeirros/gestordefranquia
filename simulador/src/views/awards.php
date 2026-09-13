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
  'MVP' => ['🏅', 'MVP da Temporada', 'O melhor jogador da liga'],
  'Finals MVP' => ['🏆', 'MVP das Finais', 'Decisivo na série do título'],
  'DPOY' => ['🛡️', 'Defensor do Ano', 'Tocos, roubos e presença'],
  'ROY' => ['🌟', 'Novato do Ano', 'O melhor calouro'],
  '6º Homem' => ['🪑', '6º Homem do Ano', 'O melhor saindo do banco'],
  'MIP' => ['📈', 'Jogador que Mais Evoluiu', 'Maior salto de OVR'],
];
$gmAwards = $gmId ? array_values(array_filter($aw, fn($a) => (int) $a['team_id'] === $gmId)) : [];
$myHist = $gmId ? League::teamSeasonHistory($gmId) : [];
$myRow = null; foreach ($myHist as $h) { if ((int) $h['season'] === $season) { $myRow = $h; break; } }
Database::setMeta('awards_seen', (string) $season);
render_header('Premiação');
?>
<div class="awards-stage <?= $isChamp ? 'is-champ' : '' ?>" style="--c1:<?= e($champ['primary_color']) ?>;--c2:<?= e($champ['secondary_color'] ?? '#111') ?>">
  <?php if ($isChamp): ?><canvas id="confetti" class="confetti"></canvas><?php endif; ?>
  <div class="as-inner">
    <div class="as-kicker">Temporada <?= $season ?> · Cerimônia de Premiação</div>
    <div class="as-trophy">🏆</div>
    <div class="as-champ-lbl"><?= $isChamp ? 'PARABÉNS, CAMPEÃO!' : 'CAMPEÃO DA LIGA' ?></div>
    <div class="as-champ"><?= team_logo($champ['abbr'], $champ['primary_color'], 'xl') ?><span><?= e(teamFull($champ)) ?></span></div>
    <?php if ($runner): ?><div class="as-runner">venceu as Finais contra <strong><?= e(teamFull($runner)) ?></strong></div><?php endif; ?>
    <?php if ($isChamp): ?><div class="as-you">A sua franquia levantou o troféu. A diretoria, a torcida e o vestiário estão em festa — o próximo desafio é repetir.</div><?php endif; ?>
  </div>
</div>

<div class="awards-grid">
  <?php foreach ($main as $type => [$icon, $label, $sub]): if (empty($byType[$type])) continue; $a = $byType[$type][0]; ?>
    <a class="award-big <?= $gmId && (int)$a['team_id'] === $gmId ? 'mine' : '' ?>" href="<?= url('player', ['id' => $a['player_id']]) ?>">
      <div class="ab-icon"><?= $icon ?></div>
      <div class="ab-label"><?= $label ?></div>
      <div class="ab-name"><?= e($a['player_name']) ?></div>
      <div class="ab-meta"><?= e($a['abbr']) ?> · <?= e($a['pos']) ?><?= $a['value'] && $a['value'] !== $a['player_name'] ? ' · ' . e($a['value']) : '' ?></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="dashboard">
  <?php for ($t = 1; $t <= 3; $t++): if (empty($byType["All-NBA $t"])) continue; ?>
  <section class="card">
    <div class="card-head"><h2>⭐ All-NBA <?= $t ?>º Time</h2></div>
    <table class="mini-table">
      <?php foreach ($byType["All-NBA $t"] as $a): ?>
        <tr><td><a href="<?= url('player', ['id' => $a['player_id']]) ?>"><?= e($a['player_name']) ?></a> <span class="muted"><?= e($a['abbr']) ?> · <?= e($a['pos']) ?></span></td>
            <td class="num muted"><?= e($a['value']) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </section>
  <?php endfor; ?>
  <?php if ($gmId): ?>
  <section class="card">
    <div class="card-head"><h2>🏟️ Sua temporada</h2></div>
    <?php if ($myRow): ?>
      <p style="font-size:14px;margin:0 0 6px"><strong><?= (int)$myRow['wins'] ?>-<?= (int)$myRow['losses'] ?></strong> · seed #<?= (int)$myRow['seed'] ?> · <?= e(League::exitLabel((int)$myRow['exit_round'], (int)$myRow['made_playoffs'])) ?></p>
    <?php endif; ?>
    <?php if ($gmAwards): ?>
      <p class="muted" style="font-size:12px;margin:0 0 4px">Prêmios individuais do seu elenco:</p>
      <ul style="margin:0;padding-left:18px;font-size:13px">
        <?php foreach ($gmAwards as $a): ?><li><?= e($a['type']) ?> — <?= e($a['player_name']) ?></li><?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="muted" style="font-size:12px">Nenhum jogador seu foi premiado nesta temporada.</p>
    <?php endif; ?>
    <p class="legend" style="margin-top:10px">Os bônus de prêmio (MVP +5M, DPOY +3M, MVP das Finais +3M, ROY +2M, 6º Homem +2M, MIP +2M, All-NBA +3/+2/+1M) entram na folha dos premiados na próxima temporada.</p>
  </section>
  <?php endif; ?>
</div>

<section class="cta-card recap-cta">
  <div class="cta-info"><span class="cta-note">Entressafra: progressão do elenco, loteria, draft e free agency</span></div>
  <a class="btn btn-primary btn-lg cta-btn" href="<?= url('home', ['action' => 'next-season']) ?>" onclick="return confirm('Rodar a entressafra e iniciar a próxima temporada?')">🏁 Iniciar próxima temporada</a>
  <div class="cta-more"><a href="<?= url('history') ?>">Ver histórico completo</a><a href="<?= url('playoffs') ?>">Chaveamento dos playoffs</a></div>
</section>

<?php if ($isChamp): ?>
<script>
(function(){
  const c = document.getElementById('confetti'); if (!c) return;
  const ctx = c.getContext('2d');
  const box = c.parentElement;
  function size(){ c.width = box.clientWidth; c.height = box.clientHeight; }
  size(); window.addEventListener('resize', size);
  const colors = [getComputedStyle(box).getPropertyValue('--c1').trim() || '#E4002B', '#ffd700', '#fff', getComputedStyle(box).getPropertyValue('--c2').trim() || '#333'];
  const P = []; for (let i = 0; i < 140; i++) P.push({x: Math.random()*c.width, y: Math.random()*-c.height, w: 6+Math.random()*6, h: 8+Math.random()*8, v: 1.2+Math.random()*2.2, a: Math.random()*Math.PI, s: (Math.random()-.5)*.1, col: colors[i%colors.length]});
  let t0 = performance.now();
  function frame(t){
    ctx.clearRect(0,0,c.width,c.height);
    P.forEach(p => { p.y += p.v; p.a += p.s; p.x += Math.sin(p.a)*.6; if (p.y > c.height + 20) { p.y = -20; p.x = Math.random()*c.width; }
      ctx.save(); ctx.translate(p.x,p.y); ctx.rotate(p.a); ctx.fillStyle = p.col; ctx.fillRect(-p.w/2,-p.h/2,p.w,p.h); ctx.restore(); });
    if (t - t0 < 14000) requestAnimationFrame(frame); else ctx.clearRect(0,0,c.width,c.height);
  }
  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) requestAnimationFrame(frame);
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
