<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Offseason.php';
render_header('Loteria do draft');

if (League::phase() !== 'lottery') {
    page_head('Loteria do draft', [
        'eyebrow' => 'Mercado',
        'sub' => 'O sorteio que define quem escolhe primeiro na nova classe de calouros.',
    ]);
    echo note('Não há loteria em andamento. Ela acontece na entressafra, antes do draft. <a href="' . url('draft') . '">Ver os drafts</a>', 'info', 'dice-5-fill');
    render_footer();
    exit;
}

$st = Offseason::lotteryState();
$r1 = $st['r1'];                 // ordem sorteada da 1ª rodada (índice 0 = pick #1)
$odds = $st['odds'];             // team_id => % de chance do 1º pick
$gm = (int) League::gmTeam();

// mapa de times para exibição
$teamMap = [];
foreach (League::allTeams() as $t) $teamMap[(int) $t['id']] = $t;

// tabela de odds (apenas os 14 da loteria), ordenada por chance (pior campanha primeiro)
arsort($odds);

// posição projetada pela campanha (a ordem das chances): base do "subiu/caiu" na revelação
$proj = [];
foreach (array_keys($odds) as $k => $tid) $proj[(int) $tid] = $k + 1;
$maxOdds = $odds ? (float) max($odds) : 0.0;
$pctTxt = fn($v): string => number_format((float) $v, 1, ',', '.') . '%';

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
        'name' => teamFull($t),
        'color' => $t['primary_color'],
        'logo' => logo_url((string) $t['abbr']),
        'record' => (int) $t['wins'] . '-' . (int) $t['losses'],
        'odds' => isset($odds[$tid]) ? $pctTxt($odds[$tid]) : '',
        'proj' => $proj[$tid] ?? 0,
        'is_gm' => $tid === $gm,
    ];
}
$total = count($reveal);
$gmOdds = ($gm && isset($odds[$gm])) ? $pctTxt($odds[$gm]) : null;

page_head('Loteria do draft', [
    'eyebrow' => 'Draft da temporada ' . (int) $st['season'],
    'sub' => 'As 14 piores campanhas disputam a ordem das primeiras escolhas. Quem perdeu mais tem mais chance, mas quem decide é o sorteio.',
]);
?>
<div class="grid cols-main lot-cols">
  <section class="lot-stage" id="loteria">
    <div class="lot-top">
      <span class="lot-live"><i aria-hidden="true"></i><span class="ll-on">Ao vivo</span><span class="ll-off">Sorteio encerrado</span></span>
      <?php if ($total): ?><span class="lot-count"><b id="lotteryCount">0</b> de <?= $total ?> reveladas</span><?php endif; ?>
    </div>

    <div class="lot-title">
      <span class="eyebrow">Sorteio das posições</span>
      <h2><?= $total ? 'Da ' . (int) $reveal[0]['pick'] . 'ª à 1ª escolha' : 'Ordem definida' ?></h2>
      <?php if ($gmOdds !== null): ?>
        <div class="row lot-you"><?= chip('Seu time está no sorteio', 'team', 'person-fill') ?><span class="muted">Chance da 1ª escolha: <b class="strong"><?= e($gmOdds) ?></b></span></div>
      <?php elseif ($gm): ?>
        <div class="row lot-you"><?= chip('Seu time está fora do sorteio', '', 'dash-circle') ?><span class="muted">A campanha deixou você fora das 14 piores.</span></div>
      <?php endif; ?>
    </div>

    <div class="lot-actions">
      <?php if ($total): ?>
        <button type="button" id="revealBtn" class="btn btn-team btn-lg"><?= bi('dice-5-fill') ?>Revelar a <?= (int) $reveal[0]['pick'] ?>ª escolha</button>
        <button type="button" id="revealAllBtn" class="btn btn-ghost"><?= bi('fast-forward-fill') ?>Revelar tudo</button>
      <?php endif; ?>
      <a id="startDraftBtn" class="btn btn-team btn-lg" href="<?= url('home', ['action' => 'start-draft']) ?>" data-busy="Abrindo o draft"<?= $total ? ' hidden' : '' ?>><?= bi('mortarboard-fill') ?>Começar o draft</a>
      <a class="btn btn-ghost" href="<?= url('draft') ?>"><?= bi('list-ol') ?>Ver a classe</a>
    </div>

    <div id="lotterySlots" class="lot-slots" aria-live="polite">
      <?php if ($total): ?>
      <div class="lot-hint" id="lotteryHint">
        <span class="lot-ball" aria-hidden="true"></span>
        <b><?= $total ?> envelopes lacrados</b>
        <span>Toque em Revelar para abrir o primeiro. A 1ª escolha é a última a sair.</span>
      </div>
      <?php else: ?>
        <?= empty_state('Sorteio sem envelopes.', 'A ordem já está definida. Abra o draft para começar.', 'dice-5') ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel pad-0 lot-odds">
    <?= panel_head('Chances da 1ª escolha', ['icon' => 'percent', 'meta' => 'Pela campanha']) ?>
    <div class="table-wrap">
      <table class="tbl compact">
        <thead><tr><th class="c">#</th><th>Time</th><th class="num">Chance</th></tr></thead>
        <tbody>
        <?php foreach ($odds as $tid => $pct): $t = $teamMap[(int) $tid] ?? null; if (!$t) continue; $mine = (int) $tid === $gm; ?>
          <tr class="<?= $mine ? 'mine' : '' ?>">
            <td class="rank"><?= (int) ($proj[(int) $tid] ?? 0) ?></td>
            <td class="t-cell"><?= team_who($t, (int) $t['wins'] . '-' . (int) $t['losses'] . ($mine ? ' · seu time' : '')) ?></td>
            <td class="num"><div class="odds"><b><?= e($pctTxt($pct)) ?></b><?= meter($maxOdds > 0 ? (float) $pct / $maxOdds * 100 : 0, $mine ? '' : 'go') ?></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<script>
window.LOTTERY = <?= json_encode($reveal, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="<?= e(asset_v('assets/js/lottery.js')) ?>"></script>
<?php render_footer(); ?>
