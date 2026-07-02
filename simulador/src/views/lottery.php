<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Offseason.php';
render_header('Loteria do Draft');

if (League::phase() !== 'lottery') {
    echo '<section class="card"><div class="card-head"><h2>🎰 Loteria do Draft</h2></div>';
    echo '<p class="muted">Não há loteria em andamento. <a href="' . url('home') . '">Voltar ao início</a></p></section>';
    render_footer();
    exit;
}

$st = Offseason::lotteryState();
$r1 = $st['r1'];                 // ordem sorteada da 1ª rodada (índice 0 = pick #1)
$odds = $st['odds'];             // team_id => % de chance do 1º pick
$gm = League::gmTeam();

// mapa de times para exibição
$teamMap = [];
foreach (League::allTeams() as $t) $teamMap[(int) $t['id']] = $t;

// tabela de odds (apenas os 14 da loteria), ordenada por chance (pior campanha primeiro)
arsort($odds);

// dados do sorteio para o JS, em ORDEM DE REVELAÇÃO (#14 -> #1)
$reveal = [];
for ($i = 13; $i >= 0; $i--) {
    if (!isset($r1[$i])) continue;
    $tid = (int) $r1[$i];
    $t = $teamMap[$tid] ?? null;
    if (!$t) continue;
    $reveal[] = [
        'pick' => $i + 1,
        'abbr' => $t['abbr'],
        'name' => $t['city'] . ' ' . $t['name'],
        'color' => $t['primary_color'],
        'is_gm' => $tid === $gm,
    ];
}
?>
<h1 class="page-title">🎰 Loteria do Draft — Temporada <?= (int)$st['season'] ?></h1>
<p class="legend">As piores campanhas têm mais chance de ficar com a 1ª escolha — mas o sorteio decide!
   Clique para revelar as escolhas, da <strong>14ª até a 1ª</strong>.</p>

<div class="dashboard">
  <section class="card">
    <div class="card-head"><h2>📊 Chances do 1º pick</h2></div>
    <table class="box-table">
      <thead><tr><th>Time</th><th>Campanha</th><th>Chance</th></tr></thead>
      <tbody>
      <?php foreach ($odds as $tid => $pct): $t = $teamMap[(int)$tid] ?? null; if (!$t) continue; ?>
        <tr class="<?= (int)$tid === $gm ? 'lottery-mine' : '' ?>">
          <td class="bx-name"><span class="dot" style="background:<?= e($t['primary_color']) ?>"></span><?= e($t['city'].' '.$t['name']) ?><?= (int)$tid===$gm?' <span class="tag">você</span>':'' ?></td>
          <td class="muted"><?= (int)$t['wins'] ?>-<?= (int)$t['losses'] ?></td>
          <td class="num"><strong><?= e($pct) ?>%</strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="card span2">
    <div class="card-head"><h2>🎱 Sorteio das posições</h2></div>
    <div id="lotterySlots" class="lottery-slots">
      <p class="muted" id="lotteryHint">Clique em <strong>Revelar próxima escolha</strong> para começar o sorteio.</p>
    </div>
    <div class="lottery-actions">
      <button id="revealBtn" class="btn btn-primary btn-lg">🎱 Revelar próxima escolha</button>
      <a id="startDraftBtn" class="btn btn-primary btn-lg" style="display:none"
         href="<?= url('home', ['action' => 'start-draft']) ?>">🎓 Iniciar o Draft →</a>
    </div>
  </section>
</div>

<script>
window.LOTTERY = <?= json_encode($reveal, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/js/lottery.js"></script>
<?php render_footer(); ?>
