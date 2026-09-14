<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Offseason.php';
require_once dirname(__DIR__) . '/PlayerFace.php';
render_header('Sala do draft');
if (League::phase() !== 'draft') {
    page_head('Sala do draft', ['eyebrow' => 'Mercado', 'sub' => 'Onde as franquias escolhem os calouros, uma escolha por vez.']);
    echo note('Não há draft em andamento. <a href="' . url('draft') . '">Ver os drafts anteriores</a>', 'info', 'mortarboard-fill');
    render_footer(); exit;
}
$st = Offseason::draftState();
$season = $st['season'];
$onClock = League::team((int) $st['on_clock']);
$origTeam = !empty($st['orig']) ? League::team((int) $st['orig']) : null;
$via = ($origTeam && (int) $st['orig'] !== (int) $st['on_clock']) ? $origTeam : null;
$gmId = (int) League::gmTeam();
$avail = Offseason::availableProspects($season);
$recent = Offseason::recentPicks($season, 200);
$gmPicksAhead = 0;
foreach (array_slice($st['order'], $st['pick']) as $en) { if ((int) $en['owner'] === $gmId) $gmPicksAhead++; }

$teamMap = [];
foreach (League::allTeams() as $t) $teamMap[(int) $t['id']] = $t;
$isUser  = !empty($st['is_user']);
$pickNo  = (int) $st['pick'] + 1;
$total   = (int) $st['total'];
$made    = (int) $st['pick'];
$next    = array_slice($st['order'], $pickNo, 5, true); // chaves = índice da escolha
$myCount = count(array_filter($recent, fn($r) => (int) $r['picked_by'] === $gmId));
$skills  = ['thr' => ['ARR', 'Arremesso de 3'], 'ins' => ['INT', 'Jogo interior'], 'def' => ['DEF', 'Defesa'],
            'pmk' => ['PAS', 'Passe'], 'reb' => ['REB', 'Rebote'], 'ath' => ['ATL', 'Atleticismo']];

if ($isUser) {
    $left = $gmPicksAhead - 1;
    $statusTxt = $left > 0 ? 'Depois desta, você ainda tem ' . ($left === 1 ? '1 escolha' : "$left escolhas") . ' neste draft.'
                           : 'Esta é a sua última escolha neste draft.';
} else {
    $statusTxt = $gmPicksAhead > 0 ? 'Você ainda tem ' . ($gmPicksAhead === 1 ? '1 escolha' : "$gmPicksAhead escolhas") . ' neste draft.'
                                   : 'Você não tem mais escolhas neste draft.';
}
?>
<div class="stack">
  <section class="dr-clock<?= $isUser ? ' is-you' : '' ?>">
    <div class="drc-pick">
      <span class="label">Escolha</span>
      <b>#<?= $pickNo ?></b>
      <small><?= (int) $st['round'] === 2 ? '2ª' : '1ª' ?> rodada · <?= $pickNo ?> de <?= $total ?></small>
    </div>
    <div class="drc-team">
      <?php if ($onClock): ?><?= team_logo($onClock['abbr'], $onClock['primary_color'] ?? '#333', 'xl') ?><?php endif; ?>
      <div class="drc-id">
        <span class="eyebrow"><?= $isUser ? 'Sua vez no draft' : 'Na vez' ?> · Temporada <?= (int) $season ?></span>
        <h1><?= $onClock ? e(teamFull($onClock)) : 'Aguardando' ?></h1>
        <?php if ($via): ?><div class="row drc-chips"><?= chip('Escolha via ' . $via['abbr'], 'info', 'arrow-left-right') ?></div><?php endif; ?>
      </div>
    </div>
    <div class="drc-status">
      <b><?= $isUser ? 'Escolha o seu calouro' : 'Os times estão escolhendo' ?></b>
      <span><?= $isUser ? 'Toque em Draftar no calouro que você quer levar. ' : '' ?><?= e($statusTxt) ?></span>
      <div class="drc-prog"><?= meter($total ? $made / $total * 100 : 0, 'go') ?><small><?= $made ?> de <?= $total ?> escolhas feitas</small></div>
    </div>
    <?php if ($next): ?>
    <div class="drc-next">
      <span class="label">Na sequência</span>
      <ol>
        <?php foreach ($next as $k => $en): $nt = $teamMap[(int) $en['owner']] ?? null; if (!$nt) continue; ?>
          <li class="<?= (int) $en['owner'] === $gmId ? 'mine' : '' ?>"><small>#<?= (int) $k + 1 ?></small><?= team_logo($nt['abbr'], $nt['primary_color'] ?? '#333', 'sm') ?><b><?= e($nt['abbr']) ?></b></li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php endif; ?>
  </section>

  <?= note('<b>Névoa de guerra.</b> Os olheiros dão notas, não números. O OVR exato só aparece depois da escolha, e o potencial nunca é certeza: tem calouro que vira estrela e tem calouro que não sai do banco.', 'info', 'eye-slash-fill') ?>

  <section class="panel pad-0" id="board">
    <?= panel_head('Prospectos disponíveis', ['icon' => 'mortarboard-fill', 'meta' => count($avail) > 40 ? 'Top 40 de ' . count($avail) : count($avail) . ' no board']) ?>
    <p class="dr-key">Board de consenso dos olheiros. <?= implode(' · ', array_map(fn($s) => '<b>' . $s[0] . '</b> ' . mb_strtolower($s[1]), $skills)) ?></p>
    <?php if (!$avail): ?>
      <?= empty_state('Nenhum calouro disponível.', 'Todos os prospectos desta classe já foram escolhidos.', 'mortarboard') ?>
    <?php else: ?>
    <div class="dr-board">
      <div class="dr-row dr-headrow" aria-hidden="true">
        <span class="dr-rk">#</span>
        <span class="dr-who">Calouro</span>
        <span class="dr-ovr">Geral</span>
        <span class="dr-sk"><?php foreach ($skills as [$ab, $full]): ?><span title="<?= e($full) ?>"><?= $ab ?></span><?php endforeach; ?></span>
        <span class="dr-act"></span>
      </div>
      <ol class="dr-list">
      <?php foreach (array_slice($avail, 0, 40) as $i => $p): ?>
        <li class="dr-row">
          <span class="dr-rk"><?= $i + 1 ?></span>
          <span class="who dr-who"><img class="face" src="<?= e(PlayerFace::url((int) $p['id'], $p['name'], $p['pos'])) ?>" alt="" loading="lazy"><span class="w-txt"><b><?= e($p['name']) ?></b><small><?= e($p['pos']) ?> · <?= (int) $p['age'] ?> anos</small></span></span>
          <span class="dr-ovr"><small>Geral</small><span class="grade <?= gradeClass($p['ovr']) ?>"><?= grade($p['ovr']) ?></span></span>
          <span class="dr-sk">
            <?php foreach ($skills as $k => [$ab, $full]): ?><span class="dr-g" title="<?= e($full) ?>"><small><?= $ab ?></small><span class="grade <?= gradeClass($p[$k]) ?>"><?= grade($p[$k]) ?></span></span><?php endforeach; ?>
          </span>
          <span class="dr-act">
            <?php if ($isUser): ?>
              <a class="btn btn-team" href="<?= url('home', ['action' => 'draft-pick', 'prospect' => $p['id']]) ?>"
                 data-confirm="Draftar <?= e($p['name']) ?> com a escolha #<?= $pickNo ?>?" data-confirm-title="Draft" data-confirm-ok="Draftar" data-busy="Registrando a escolha">Draftar</a>
            <?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
      </ol>
    </div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <?= panel_head('Escolhas feitas', ['icon' => 'list-ol', 'right' => $myCount ? chip('Suas: ' . $myCount, 'team', 'person-fill') : '', 'meta' => count($recent) . ' de ' . $total]) ?>
    <?php if (!$recent): ?>
      <?= empty_state('O draft está começando.', 'As escolhas aparecem aqui assim que cada time decidir.', 'hourglass-split') ?>
    <?php else: ?>
    <ol class="dr-picks">
      <?php foreach ($recent as $r): $pt = $teamMap[(int) $r['picked_by']] ?? null; $mine = (int) $r['picked_by'] === $gmId; ?>
        <li class="dr-pick<?= $mine ? ' mine' : '' ?>">
          <span class="dp-no">#<?= (int) $r['pick_no'] ?></span>
          <?= team_logo((string) $r['team_abbr'], $pt['primary_color'] ?? '#333', 'sm') ?>
          <span class="w-txt"><b><?= e($r['name']) ?></b><small><?= e($r['team_abbr']) ?> · <?= e($r['pos']) ?> · <?= (int) $r['age'] ?> anos</small></span>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </section>
</div>
<?php render_footer(); ?>
