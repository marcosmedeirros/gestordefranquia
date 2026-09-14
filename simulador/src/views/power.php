<?php
require_once dirname(__DIR__) . '/helpers.php';

$rank = League::powerRankings();
$gmId = (int) League::gmTeam();
$mine = null;
foreach ($rank as $t) { if ((int) $t['id'] === $gmId) { $mine = $t; break; } }
$vals  = array_map(fn($t) => (float) $t['power'], $rank);
$pMax  = $vals ? max($vals) : 0.0;
$pMin  = $vals ? min($vals) : 0.0;
$games = array_sum(array_map(fn($t) => (int) $t['wins'] + (int) $t['losses'], $rank));
$sign  = fn(float $v): string => ($v > 0 ? '+' : ($v < 0 ? '-' : '')) . number_format(abs($v), 1);
$conf  = fn(array $t): string => $t['conf'] === 'E' ? 'Leste' : 'Oeste';

render_header('Power ranking');
page_head('Power ranking', [
    'eyebrow' => 'Liga · Temporada ' . League::season(),
    'sub' => 'Quem está mais forte agora. O índice junta aproveitamento, saldo de pontos, sequência e a qualidade do elenco.',
    'actions' => $mine ? chip('Seu time: ' . (int) $mine['rank'] . 'º', 'team', 'star-fill') : '',
]);
?>
<div class="stack">
  <?php if ($games === 0): ?>
    <?= note('Ninguém jogou ainda. Por enquanto o índice mostra só a força de cada elenco.', 'info', 'hourglass-split') ?>
  <?php endif; ?>

  <?php if (!$rank): ?>
    <section class="panel"><?= empty_state('Sem times para ranquear.', '', 'list-ol') ?></section>
  <?php else: ?>
  <div class="pw-top">
    <?php foreach (array_slice($rank, 0, 3) as $t): ?>
      <a class="pw-card<?= (int) $t['id'] === $gmId ? ' mine' : '' ?>" href="<?= url('team', ['id' => $t['id']]) ?>">
        <span class="pw-rk"><?= (int) $t['rank'] ?></span>
        <?= team_logo((string) $t['abbr'], (string) ($t['primary_color'] ?? '#333'), 'lg') ?>
        <span class="pw-txt">
          <b><?= e(teamFull($t)) ?></b>
          <small><?= (int) $t['wins'] ?>-<?= (int) $t['losses'] ?> · saldo <?= $sign((float) $t['avg_margin']) ?></small>
        </span>
        <span class="pw-idx"><b><?= number_format((float) $t['power'], 1) ?></b><small>Índice</small></span>
      </a>
    <?php endforeach; ?>
  </div>

  <section class="panel pad-0 pw-panel">
    <?= panel_head('Ranking completo', ['icon' => 'list-ol', 'meta' => count($rank) . ' times']) ?>
    <div class="table-wrap">
      <table class="tbl pw-tbl">
        <thead><tr>
          <th class="c">#</th>
          <th>Time</th>
          <th class="num" title="Saldo de pontos por jogo">Saldo</th>
          <th class="c hide-sm" title="Sequência atual">Seq.</th>
          <th class="hide-sm"><span class="sr-only">Força relativa</span></th>
          <th class="num">Índice</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rank as $t):
          $stk = (int) ($t['streak'] ?? 0);
          $m   = (float) $t['avg_margin'];
          $bar = $pMax > $pMin ? 6 + ((float) $t['power'] - $pMin) / ($pMax - $pMin) * 94 : 50; ?>
          <tr<?= (int) $t['id'] === $gmId ? ' class="mine"' : '' ?>>
            <td class="rank"><?= (int) $t['rank'] ?></td>
            <td><?= team_who($t, $conf($t) . ' · ' . (int) $t['wins'] . '-' . (int) $t['losses']) ?></td>
            <td class="num <?= $m > 0 ? 'pos' : ($m < 0 ? 'neg' : 'dim') ?>"><?= $sign($m) ?></td>
            <td class="c hide-sm"><?= $stk > 0 ? chip('V' . $stk, 'ok') : ($stk < 0 ? chip('D' . abs($stk), 'bad') : '<span class="dim">—</span>') ?></td>
            <td class="hide-sm pw-bar"><?= meter($bar) ?></td>
            <td class="num"><span class="pw-val"><?= number_format((float) $t['power'], 1) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="pw-foot">Saldo: pontos a favor menos pontos contra, por jogo. Seq.: sequência atual, V para vitórias e D para derrotas.</p>
  </section>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
