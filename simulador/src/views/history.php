<?php
require_once dirname(__DIR__) . '/helpers.php';

/** Resultado de uma temporada da franquia como chip: campeão, finais, playoffs ou fora. */
function history_exit_chip(array $h): string
{
    $exit = (int) $h['exit_round'];
    $made = (int) $h['made_playoffs'];
    if ((int) $h['champion'] || $exit >= 5) return chip('Campeão', 'ok', 'trophy-fill');
    $label = League::exitLabel($exit, $made);
    if ($exit === 4) return chip($label, 'info');
    if ($exit >= 1) return chip($label);
    return chip($label, $made ? 'warn' : 'bad');
}

/** Uma movimentação da liga (troca, contratação, dispensa, draft...). */
function history_tx(array $m): void
{
    static $types = [
        'troca'       => ['arrow-left-right', 'Troca', 'team'],
        'free agency' => ['pen-fill', 'Contratação', 'ok'],
        'renovação'   => ['arrow-repeat', 'Renovação', 'ok'],
        'dispensa'    => ['door-open-fill', 'Dispensa', 'bad'],
        'draft'       => ['mortarboard-fill', 'Draft', 'info'],
        'retire'      => ['person-walking', 'Aposentadoria', ''],
        'vitrine'     => ['megaphone-fill', 'Vitrine', 'go'],
        'punição'     => ['hammer', 'Punição', 'bad'],
        'expansão'    => ['plus-circle-fill', 'Expansão', 'go'],
    ];
    [$icon, $label, $tone] = $types[$m['type']] ?? ['dot', ucfirst((string) $m['type']), ''];
    $when = $label . ' · Temporada ' . (int) $m['season'] . ((int) $m['day'] ? ' · dia ' . (int) $m['day'] : '');
    echo '<div class="tx' . ($tone !== '' ? ' t-' . $tone : '') . '"><span class="tx-ic">' . bi($icon) . '</span>'
        . '<div><div class="tx-txt">' . e($m['description']) . '</div><span class="tx-when">' . e($when) . '</span></div></div>';
}

$champions    = League::champions();
$titles       = League::titlesRanking();
$awardSeasons = League::awardSeasons();
$sel          = (int) ($_GET['season'] ?? ($awardSeasons[0] ?? League::season()));
$awards       = League::awards($sel);
$records      = League::seasonRecords('pts', 10);
$moves        = League::transactions(null, 40);
$byType = [];
foreach ($awards as $a) { $byType[$a['type']][] = $a; }

$gmId   = (int) League::gmTeam();
$myTeam = $gmId ? League::team($gmId) : null;
$traj   = $myTeam ? League::teamSeasonHistory($gmId) : [];
$fs     = $myTeam ? League::franchiseSummary($gmId) : null;

$teamsById = [];
$idByAbbr = [];
foreach (League::allTeams() as $t) { $teamsById[(int) $t['id']] = $t; $idByAbbr[$t['abbr']] = (int) $t['id']; }

render_header('História');
page_head('História da liga', [
    'eyebrow' => 'Liga',
    'sub' => 'Campeões, prêmios, recordes e o caminho da sua franquia, temporada a temporada.',
]);
?>
<div class="stack">

<?php if ($myTeam): ?>
<section class="panel hot">
  <div class="fr-id">
    <?= team_logo($myTeam['abbr'], $myTeam['primary_color'] ?? '#333', 'lg') ?>
    <div>
      <span class="eyebrow">Sua franquia</span>
      <h2><?= e(teamFull($myTeam)) ?></h2>
    </div>
  </div>
  <div class="stats fr-stats">
    <?= stat_tile('Títulos', (string) $fs['titles'], '', $fs['titles'] ? 'pos' : '') ?>
    <?= stat_tile('Finais', (string) $fs['finals']) ?>
    <?= stat_tile('Playoffs', (string) $fs['playoffs'], $fs['seasons'] ? 'em ' . $fs['seasons'] . ($fs['seasons'] === 1 ? ' temporada' : ' temporadas') : '') ?>
    <?= stat_tile('Melhor campanha', $fs['best'] ? (int) $fs['best']['wins'] . '-' . (int) $fs['best']['losses'] : '—', $fs['best'] ? 'Temporada ' . (int) $fs['best']['season'] : '') ?>
  </div>
  <?php if (!$traj): ?>
    <?= empty_state('Nenhuma temporada concluída.', 'A trajetória da franquia aparece aqui quando a primeira temporada acabar.', 'hourglass-split') ?>
  <?php else: ?>
    <div class="sub-h">Temporada a temporada</div>
    <div class="table-wrap">
      <table class="tbl compact">
        <thead><tr><th class="c">Temp.</th><th class="num">Campanha</th><th class="c">Seed</th><th>Resultado</th></tr></thead>
        <tbody>
        <?php foreach ($traj as $h): ?>
          <tr>
            <td class="rank"><?= (int) $h['season'] ?></td>
            <td class="num"><b><?= (int) $h['wins'] ?></b>-<?= (int) $h['losses'] ?></td>
            <td class="c"><?= (int) $h['seed'] ? (int) $h['seed'] . 'º' : '<span class="dim">—</span>' ?></td>
            <td><?= history_exit_chip($h) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="grid cols-main">
  <div class="stack">
    <section class="panel" id="campeoes">
      <?= panel_head('Campeões', ['icon' => 'trophy-fill', 'meta' => $champions ? (count($champions) === 1 ? '1 temporada' : count($champions) . ' temporadas') : '']) ?>
      <?php if (!$champions): ?>
        <?= empty_state('Nenhum campeão ainda.', 'O primeiro título sai no fim dos playoffs da primeira temporada.', 'trophy') ?>
      <?php else: ?>
      <div class="table-wrap">
        <table class="tbl hist-tbl">
          <thead><tr><th class="c">Temp.</th><th>Campeão</th><th>MVP das finais</th></tr></thead>
          <tbody>
          <?php foreach ($champions as $c):
            $ct = ['id' => $c['team_id'], 'abbr' => $c['champ_abbr'], 'city' => $c['champ_city'], 'name' => $c['champ_name'], 'primary_color' => $c['champ_color']]; ?>
            <tr class="<?= (int) $c['team_id'] === $gmId ? 'mine' : '' ?>">
              <td class="rank"><?= (int) $c['season'] ?></td>
              <td><?= team_who($ct, !empty($c['run_abbr']) ? 'Venceu ' . $c['run_abbr'] . ' nas finais' : '') ?></td>
              <td>
                <?php if (!empty($c['fmvp_id']) && !empty($c['fmvp_name'])): ?>
                  <a class="link" href="<?= url('player', ['id' => $c['fmvp_id']]) ?>"><?= e($c['fmvp_name']) ?></a>
                <?php else: ?><span class="dim">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <section class="panel" id="premios">
      <?= panel_head('Prêmios', ['icon' => 'star-fill', 'meta' => count($awardSeasons) === 1 ? 'Temporada ' . $sel : '']) ?>
      <?php if (count($awardSeasons) > 1): ?>
      <nav class="seg hist-seasons" aria-label="Temporada dos prêmios">
        <?php foreach ($awardSeasons as $s): ?>
          <a class="<?= $s === $sel ? 'on' : '' ?>" href="<?= url('history', ['season' => $s]) ?>#premios">Temporada <?= $s ?></a>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>

      <?php if (!$awards): ?>
        <?= empty_state('Sem prêmios registrados.', 'Os prêmios saem no fim dos playoffs de cada temporada.', 'award') ?>
      <?php else:
        $singles = ['MVP' => ['MVP', 'star-fill'], 'Finals MVP' => ['MVP das finais', 'trophy-fill'],
                    'DPOY' => ['Defensor do ano', 'shield-fill'], 'ROY' => ['Novato do ano', 'stars']]; ?>
        <div class="aw-grid">
          <?php foreach ($singles as $type => [$label, $icon]): if (empty($byType[$type])) continue; $a = $byType[$type][0]; ?>
            <a class="aw-card" href="<?= url('player', ['id' => $a['player_id']]) ?>">
              <span class="label"><?= bi($icon) ?><?= e($label) ?></span>
              <b><?= e($a['player_name']) ?></b>
              <small><?= e(trim(($a['abbr'] ?? '') . ' · ' . ($a['value'] ?? ''), ' ·')) ?></small>
            </a>
          <?php endforeach; ?>
        </div>

        <?php $quintetos = ['All-NBA 1' => '1º quinteto', 'All-NBA 2' => '2º quinteto', 'All-NBA 3' => '3º quinteto'];
          if (array_intersect_key($byType, $quintetos)): ?>
        <div class="sub-h">Quintetos All-NBA</div>
        <div class="allnba">
          <?php foreach ($quintetos as $tt => $lbl): if (empty($byType[$tt])) continue; ?>
          <div class="allnba-row">
            <span class="label"><?= e($lbl) ?></span>
            <div class="allnba-players">
              <?php foreach ($byType[$tt] as $a): ?>
                <a class="ap" href="<?= url('player', ['id' => $a['player_id']]) ?>"><?= e($a['player_name']) ?><small><?= e($a['abbr'] ?? '') ?></small></a>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>

  <div class="stack">
    <section class="panel">
      <?= panel_head('Dinastias', ['icon' => 'award-fill', 'meta' => 'mais títulos']) ?>
      <?php if (!$titles): ?>
        <?= empty_state('Sem títulos ainda.', 'O ranking começa com o primeiro campeão.', 'award') ?>
      <?php else: ?>
      <div class="table-wrap">
        <table class="tbl compact hist-tbl">
          <tbody>
          <?php foreach ($titles as $i => $t): ?>
            <tr class="<?= $myTeam && $t['abbr'] === $myTeam['abbr'] ? 'mine' : '' ?>">
              <td class="rank"><?= $i + 1 ?></td>
              <td><?= team_who($t + ['id' => $idByAbbr[$t['abbr']] ?? 0]) ?></td>
              <td class="num"><span class="hist-titles"><?= bi('trophy-fill') ?><?= (int) $t['titles'] ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <?= panel_head('Recordes de pontos', ['icon' => 'graph-up-arrow', 'meta' => 'média numa temporada']) ?>
      <?php if (!$records): ?>
        <?= empty_state('Sem recordes ainda.', 'Entram aqui jogadores com pelo menos 20 jogos numa temporada.', 'graph-up-arrow') ?>
      <?php else: ?>
      <div class="table-wrap">
        <table class="tbl compact hist-tbl">
          <thead><tr><th class="c">#</th><th>Jogador</th><th class="c">OVR</th><th class="num">PTS</th></tr></thead>
          <tbody>
          <?php foreach ($records as $i => $rec): $rt = $teamsById[(int) ($rec['team_id'] ?? 0)] ?? null; ?>
            <tr>
              <td class="rank"><?= $i + 1 ?></td>
              <td>
                <span class="who">
                  <?= $rt ? team_logo($rt['abbr'], $rt['primary_color'] ?? '#333', 'sm') : '' ?>
                  <span class="w-txt"><b><?= e($rec['name']) ?></b><small>Temporada <?= (int) $rec['season'] ?><?= $rt ? ' · ' . e($rt['abbr']) : '' ?></small></span>
                </span>
              </td>
              <td class="c"><?= ovr_badge($rec['ovr'], 'sm') ?></td>
              <td class="num"><b class="hist-avg"><?= e(number_format((float) $rec['avg'], 1)) ?></b></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <?= panel_head('Movimentações', ['icon' => 'arrow-left-right', 'meta' => 'mais recentes']) ?>
      <?php if (!$moves): ?>
        <?= empty_state('Nenhuma movimentação ainda.', 'Trocas, contratações, dispensas e draft aparecem aqui.', 'arrow-left-right') ?>
      <?php else: ?>
        <div class="tx-list">
          <?php foreach (array_slice($moves, 0, 12) as $m) history_tx($m); ?>
        </div>
        <?php if (count($moves) > 12): ?>
        <details class="tx-more">
          <summary class="more">Ver mais <?= count($moves) - 12 ?><?= bi('chevron-down') ?></summary>
          <div class="tx-list">
            <?php foreach (array_slice($moves, 12) as $m) history_tx($m); ?>
          </div>
        </details>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>
</div>

</div>
<?php render_footer(); ?>
