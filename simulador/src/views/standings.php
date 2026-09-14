<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once __DIR__ . '/_standings_table.php';

$view     = ($_GET['view'] ?? '') === 'geral' ? 'geral' : 'conf';
$phase    = League::phase();
$gmId     = (int) League::gmTeam();
$gm       = $gmId ? League::team($gmId) : null;
$confName = ['E' => 'Leste', 'W' => 'Oeste'];
$confs    = ['E' => League::standings('E'), 'W' => League::standings('W')];
if ($gm && $gm['conf'] === 'W') $confs = array_reverse($confs, true); // sua conferência primeiro

$games = 0;
foreach ($confs as $list) foreach ($list as $r) $games += (int) $r['wins'] + (int) $r['losses'];

// Seu time: posição, campanha e distância até a linha de corte
$me   = null;
$rows = $gm ? ($confs[$gm['conf']] ?? []) : [];
foreach ($rows as $r) { if ((int) $r['id'] === $gmId) { $me = $r; break; } }
// jogos de distância de $a para $b (positivo: $a está à frente)
$gap = fn(array $a, array $b): float => (((int) $a['wins'] - (int) $a['losses']) - ((int) $b['wins'] - (int) $b['losses'])) / 2;

$tiles = '';
if ($me && $games > 0) {
    $seed = (int) $me['seed'];
    $w    = (int) $me['wins'];
    $l    = (int) $me['losses'];
    $stk  = (int) ($me['streak'] ?? 0);
    $tone = $seed <= 6 ? 'pos' : ($seed <= 10 ? 'warn' : 'neg');

    if ($phase !== 'regular') {
        $zone = $seed <= 6 ? ['Faixa final', 'Direto', 'vaga direta nos playoffs', 'pos']
              : ($seed <= 10 ? ['Faixa final', 'Play-in', 'foi para o play-in', 'warn'] : ['Faixa final', 'Fora', 'ficou fora dos playoffs', 'neg']);
    } elseif ($seed <= 6) {
        $d = isset($rows[6]) ? $gap($me, $rows[6]) : 0.0;
        $zone = ['Vaga direta', $d > 0 ? '+' . number_format($d, 1) : '0.0', $d > 0 ? 'jogos à frente do 7º' : 'empatado com o 7º', $d > 0 ? 'pos' : 'warn'];
    } elseif ($seed <= 10) {
        $d = $gap($rows[5], $me);
        $zone = ['Até o 6º', $d > 0 ? '-' . number_format($d, 1) : '0.0', $d > 0 ? 'jogos atrás da vaga direta' : 'empatado com o 6º', 'warn'];
    } else {
        $d = $gap($rows[9], $me);
        $zone = ['Até o 10º', $d > 0 ? '-' . number_format($d, 1) : '0.0', $d > 0 ? 'jogos atrás do play-in' : 'empatado com o 10º', 'neg'];
    }

    $tiles = stat_tile('Sua posição', $seed . 'º', 'na Conferência ' . $confName[$gm['conf']], $tone)
        . stat_tile('Campanha', $w . '-' . $l, number_format((float) $me['pct'] * 100, 1) . '% de aproveitamento')
        . stat_tile($zone[0], $zone[1], $zone[2], $zone[3])
        . stat_tile('Sequência', $stk > 0 ? 'V' . $stk : ($stk < 0 ? 'D' . abs($stk) : '—'),
            $stk > 1 ? 'vitórias seguidas' : ($stk === 1 ? 'venceu o último' : ($stk < -1 ? 'derrotas seguidas' : ($stk === -1 ? 'perdeu o último' : 'sem sequência'))),
            $stk > 0 ? 'pos' : ($stk < 0 ? 'neg' : ''));
}

$foot = 'JA: jogos atrás do líder. Seq.: sequência atual, V para vitórias e D para derrotas.';

render_header('Classificação');
page_head('Classificação', [
    'eyebrow' => 'Liga · Temporada ' . League::season(),
    'sub' => 'Do 1º ao 6º de cada conferência vão direto aos playoffs. Do 7º ao 10º disputam o play-in pelas duas últimas vagas.',
]);
?>
<div class="stack">
  <?php if ($games === 0): ?>
    <?= note('A temporada ainda não começou. A tabela se mexe a partir da estreia.', 'info', 'hourglass-split') ?>
  <?php elseif ($tiles !== ''): ?>
    <div class="stats"><?= $tiles ?></div>
  <?php endif; ?>

  <div class="st-bar">
    <nav class="seg" aria-label="Tipo de tabela">
      <a class="<?= $view === 'conf' ? 'on' : '' ?>" href="<?= url('standings') ?>">Conferências</a>
      <a class="<?= $view === 'geral' ? 'on' : '' ?>" href="<?= url('standings', ['view' => 'geral']) ?>">Liga toda</a>
    </nav>
    <?php if ($view === 'conf'): ?>
    <div class="st-legend">
      <?= chip('1º ao 6º: playoffs', 'ok', 'check-circle-fill') ?>
      <?= chip('7º ao 10º: play-in', 'warn', 'shuffle') ?>
      <?= chip('11º em diante: fora', '', 'x-circle') ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($view === 'geral'):
    $all = League::standings(); ?>
    <section class="panel pad-0 st-panel">
      <?= panel_head('Liga toda', ['icon' => 'list-ol', 'meta' => count($all) . ' times pelo aproveitamento']) ?>
      <?php renderStandings($all, false, $gmId, false); ?>
      <p class="st-foot"><?= e($foot) ?></p>
    </section>
  <?php else: ?>
    <div class="st-grid">
      <?php foreach ($confs as $c => $list): ?>
      <section class="panel pad-0 st-panel">
        <?= panel_head('Conferência ' . $confName[$c], [
            'icon' => 'trophy-fill',
            'right' => ($me && $gm['conf'] === $c && $games > 0) ? chip('Você: ' . (int) $me['seed'] . 'º', 'team', 'star-fill') : '',
        ]) ?>
        <?php renderStandings($list, false, $gmId); ?>
        <p class="st-foot"><?= e($foot) ?> A linha tracejada marca o corte do 6º e do 10º.</p>
      </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
