<?php
require_once dirname(__DIR__) . '/helpers.php';

$id = (int) ($_GET['id'] ?? 0);
$t  = $id > 0 ? League::team($id) : null;
if (!$t) {
    render_header('Time');
    page_head('Time não encontrado', ['eyebrow' => 'Liga']);
    echo note('Esse time não existe neste save. <a href="' . url('teams') . '">Ver todos os times</a>', 'warn');
    render_footer();
    exit;
}

$phase    = League::phase();
$gmId     = (int) League::gmTeam();
$isMine   = $gmId === $id;
$confName = $t['conf'] === 'E' ? 'Leste' : 'Oeste';
$divName  = ['Atlantic' => 'Atlântico', 'Central' => 'Central', 'Southeast' => 'Sudeste',
             'Northwest' => 'Noroeste', 'Pacific' => 'Pacífico', 'Southwest' => 'Sudoeste'][(string) $t['div']] ?? (string) $t['div'];
$seed = null;
foreach (League::standings((string) $t['conf']) as $s) { if ((int) $s['id'] === $id) { $seed = (int) $s['seed']; break; } }

$roster   = League::rosterFull($id);
$sched    = League::teamSchedule($id);
$picks    = League::teamPicks($id);
$payroll  = League::teamPayroll($id);
$capMax   = Cap::max();
$strength = League::teamStrength($id);
$scout    = League::scoutReport($id, ($gmId && !$isMine) ? $gmId : null);

$played   = array_values(array_filter($sched, fn($g) => !empty($g['played'])));
$upcoming = array_values(array_filter($sched, fn($g) => empty($g['played'])));
$recent   = array_reverse(array_slice($played, -5));
$form     = array_map(fn($g) => (bool) $g['win'], array_slice($played, -5));
$next     = $upcoming[0] ?? null;
$lastG    = $played ? $played[count($played) - 1] : null;
$w        = (int) $t['wins'];
$l        = (int) $t['losses'];
$gp       = $w + $l;
$streak   = (int) $t['streak'];
$chem     = (int) $t['chemistry'];

// Médias por jogador e totais do elenco atual
$sum = ['pts' => 0, 'reb' => 0, 'ast' => 0, 'stl' => 0, 'blk' => 0, 'fgm' => 0, 'fga' => 0, 'tpm' => 0, 'tpa' => 0];
foreach ($roster as &$p) {
    $g = (int) $p['gp'];
    foreach (['pts', 'reb', 'ast', 'stl', 'blk'] as $k) $p['_' . $k] = $g ? (int) $p['s_' . $k] / $g : 0.0;
    $p['_fgp'] = (int) $p['s_fga'] ? (int) $p['s_fgm'] / (int) $p['s_fga'] * 100 : 0.0;
    foreach ($sum as $k => $v) $sum[$k] += (int) $p['s_' . $k];
}
unset($p);

// Quinteto titular (antes de qualquer ordenação da tabela), na ordem das posições
$posOrder = ['PG' => 1, 'SG' => 2, 'SF' => 3, 'PF' => 4, 'C' => 5];
$starters = array_values(array_filter($roster, fn($p) => !empty($p['is_starter'])));
if (!$starters) $starters = array_slice($roster, 0, 5);
usort($starters, fn($a, $b) => ($posOrder[$a['pos']] ?? 9) <=> ($posOrder[$b['pos']] ?? 9));

// Ordenação da tabela do elenco (?sort=&dir=); sem parâmetro fica titulares e OVR
$sortable = ['ovr' => 'ovr', 'age' => 'age', 'gp' => 'gp', 'pts' => '_pts', 'reb' => '_reb', 'ast' => '_ast', 'stl' => '_stl', 'blk' => '_blk', 'fgp' => '_fgp'];
$sort = array_key_exists((string) ($_GET['sort'] ?? ''), $sortable) ? (string) $_GET['sort'] : '';
$dir  = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
if ($sort !== '') {
    $key = $sortable[$sort];
    usort($roster, fn($a, $b) => $dir === 'asc' ? ((float) $a[$key] <=> (float) $b[$key]) : ((float) $b[$key] <=> (float) $a[$key]));
}
$th = function (string $label, string $col, string $cls = 'num') use ($id, $sort, $dir): string {
    $on   = $sort === $col;
    $flip = $on && $dir === 'desc' ? 'asc' : 'desc';
    return '<th class="' . $cls . ' sort"' . ($on ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>'
        . '<a class="tm-sort' . ($on ? ' on' : '') . '" href="' . e(url('team', ['id' => $id, 'sort' => $col, 'dir' => $flip])) . '#elenco">'
        . e($label) . ($on ? bi($dir === 'asc' ? 'caret-up-fill' : 'caret-down-fill') : '') . '</a></th>';
};
$gradeCls = ['A+' => 'g-a', 'A' => 'g-a', 'B+' => 'g-a', 'B' => 'g-b', 'C+' => 'g-b', 'C' => 'g-c', 'D+' => 'g-d', 'D' => 'g-d'];
$dash = '<span class="dim">—</span>';
$pColor = (string) ($t['primary_color'] ?? '#1a1a2e');

render_header(teamFull($t));
?>
<div class="stack">
  <section class="tm-hero<?= ($next || $lastG) ? '' : ' solo' ?>" style="<?= e(team_theme_style($t)) ?>">
    <div class="tm-id">
      <?= team_logo((string) $t['abbr'], (string) ($t['primary_color'] ?? '#333'), 'xl') ?>
      <div class="tm-id-txt">
        <span class="eyebrow">Conferência <?= e($confName) ?> · Divisão <?= e($divName) ?></span>
        <h1><?= e(teamFull($t)) ?></h1>
        <div class="row tm-meta">
          <span class="tm-rec" title="Campanha"><?= $w ?>-<?= $l ?></span>
          <?php if ($seed && $gp): ?><?= chip($seed . 'º no ' . $confName, $seed <= 6 ? 'ok' : ($seed <= 10 ? 'warn' : 'bad')) ?><?php endif; ?>
          <?php if ($streak): ?>
            <?= chip(abs($streak) === 1 ? ($streak > 0 ? 'Venceu o último' : 'Perdeu o último') : abs($streak) . ($streak > 0 ? ' vitórias seguidas' : ' derrotas seguidas'), $streak > 0 ? 'ok' : 'bad') ?>
          <?php endif; ?>
          <?= $form ? form_boxes($form) : '' ?>
          <?php if ($isMine): ?><?= chip('Seu time', 'team', 'star-fill') ?><?php endif; ?>
        </div>
        <p class="tm-schemes">Ataque: <b class="strong"><?= e($t['scheme_off'] ?? '—') ?></b> · Defesa: <b class="strong"><?= e($t['scheme_def'] ?? '—') ?></b></p>
      </div>
    </div>

    <?php if ($next):
      $series = ($phase === 'playoffs' && !empty($next['series_id'])) ? League::seriesStatus((int) $next['series_id']) : null; ?>
    <div class="tm-side">
      <a class="tm-card" href="<?= url('game', ['id' => $next['id']]) ?>">
        <span class="tm-card-h">Próximo jogo</span>
        <div class="matchup">
          <div class="mu-side"><?= team_logo((string) $next['away_abbr'], (string) ($next['away_color'] ?? '#333'), 'lg') ?><b><?= e($next['away_abbr']) ?></b><small>Visitante</small></div>
          <div class="mu-mid">@</div>
          <div class="mu-side"><?= team_logo((string) $next['home_abbr'], (string) ($next['home_color'] ?? '#333'), 'lg') ?><b><?= e($next['home_abbr']) ?></b><small>Mandante</small></div>
        </div>
        <span class="tm-when"><?= e(League::dateLabel((int) $next['day'])) ?><?= $series ? ' · ' . e($series['round_name'] . ' · Jogo ' . $series['game'] . ($series['high_wins'] + $series['low_wins'] ? ', ' . $series['lead'] : '')) : '' ?></span>
      </a>
    </div>
    <?php elseif ($lastG):
      $awayWon = (int) $lastG['away_pts'] > (int) $lastG['home_pts']; ?>
    <div class="tm-side">
      <a class="tm-card" href="<?= url('game', ['id' => $lastG['id']]) ?>">
        <span class="tm-card-h">Último jogo</span>
        <div class="matchup">
          <div class="mu-side<?= $awayWon ? '' : ' lost' ?>"><?= team_logo((string) $lastG['away_abbr'], (string) ($lastG['away_color'] ?? '#333'), 'lg') ?><b><?= e($lastG['away_abbr']) ?></b><span class="mu-score"><?= (int) $lastG['away_pts'] ?></span></div>
          <div class="mu-mid">Final</div>
          <div class="mu-side<?= $awayWon ? ' lost' : '' ?>"><?= team_logo((string) $lastG['home_abbr'], (string) ($lastG['home_color'] ?? '#333'), 'lg') ?><b><?= e($lastG['home_abbr']) ?></b><span class="mu-score"><?= (int) $lastG['home_pts'] ?></span></div>
        </div>
        <span class="tm-when"><?= e(League::dateLabel((int) $lastG['day'])) ?> · <?= !empty($lastG['win']) ? 'vitória' : 'derrota' ?></span>
      </a>
    </div>
    <?php endif; ?>
  </section>

  <div class="stats">
    <?= stat_tile('Folha salarial', Cap::m($payroll), 'Teto de ' . Cap::m($capMax), $payroll > $capMax ? 'neg' : '') ?>
    <?= stat_tile('Força do elenco', (string) $strength, 'Soma dos 8 maiores OVR') ?>
    <?= stat_tile('Química', (string) $chem, $chem >= 70 ? 'Elenco entrosado' : ($chem <= 58 ? 'Precisa de vitórias' : 'Dentro do normal'), $chem >= 70 ? 'pos' : ($chem <= 58 ? 'warn' : '')) ?>
    <?= stat_tile('Pontos por jogo', $gp ? number_format($sum['pts'] / $gp, 1) : '—', $sum['fga'] ? number_format($sum['fgm'] / $sum['fga'] * 100, 1) . '% nos arremessos' : 'Sem jogos ainda') ?>
  </div>

  <div class="grid cols-main tm-cols">
    <div class="stack">
      <?php if ($starters): ?>
      <section class="panel">
        <?= panel_head('Quinteto titular', ['icon' => 'star-fill', 'more' => $isMine ? ['Escalação', url('lineup')] : null]) ?>
        <div class="pcards tm-pcards">
          <?php foreach ($starters as $p):
            $inj = (int) ($p['injury_games'] ?? 0);
            $rookie = (int) ($p['seasons_pro'] ?? 1) === 0; ?>
            <a class="pcard<?= $isMine ? ' starter' : '' ?>" href="<?= url('player', ['id' => $p['id']]) ?>">
              <?= player_photo((int) ($p['nba_id'] ?? 0), (string) $p['name'], $pColor, 'md', 'face', (int) $p['id'], (string) $p['pos']) ?>
              <span class="pc-txt">
                <b><?= e($p['name']) ?></b>
                <small><?= e($p['pos']) ?> · <?= (int) $p['age'] ?> anos<?= (int) $p['gp'] ? ' · ' . number_format($p['_pts'], 1) . ' pts' : '' ?></small>
                <?php if ($inj || $rookie): ?>
                <span class="pc-flags"><?= $inj ? chip('Lesão · ' . $inj . ' j', 'bad', 'bandaid-fill') : '' ?><?= $rookie ? chip('Calouro', 'info') : '' ?></span>
                <?php endif; ?>
              </span>
              <?= ovr_badge($p['ovr']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <section class="panel pad-0 tm-panel" id="elenco">
        <?= panel_head('Elenco', ['icon' => 'people-fill', 'meta' => count($roster) . ' jogadores']) ?>
        <?php if (!$roster): ?>
          <?= empty_state('Elenco vazio.', '', 'people') ?>
        <?php else: ?>
        <div class="table-wrap">
          <table class="tbl compact tm-tbl">
            <thead><tr>
              <th>Jogador</th>
              <?= $th('OVR', 'ovr', 'c') ?>
              <th class="c hide-sm" title="Potencial na nota dos olheiros">Pot.</th>
              <?= $th('Idade', 'age', 'num hide-sm') ?>
              <?= $th('Jogos', 'gp', 'num hide-sm') ?>
              <?= $th('Pts', 'pts') ?>
              <?= $th('Reb', 'reb', 'num hide-sm') ?>
              <?= $th('Ast', 'ast', 'num hide-sm') ?>
              <?= $th('RB', 'stl', 'num hide-sm') ?>
              <?= $th('Toc', 'blk', 'num hide-sm') ?>
              <?= $th('FG%', 'fgp', 'num hide-sm') ?>
            </tr></thead>
            <tbody>
            <?php foreach ($roster as $p):
              $g      = (int) $p['gp'];
              $inj    = (int) ($p['injury_games'] ?? 0);
              $rookie = (int) ($p['seasons_pro'] ?? 1) === 0;
              $small  = implode(' · ', array_filter([
                  !empty($p['is_starter']) ? 'Titular' : '',
                  $p['pos'] . ' · ' . (int) $p['age'] . ' anos',
                  $rookie ? 'calouro' : '',
                  $inj ? 'lesão, ' . $inj . ' j' : '',
              ]));
              $pot = potGrade($p); ?>
              <tr<?= $inj ? ' class="tm-inj"' : '' ?>>
                <td><?= player_who($p, $small, $pColor) ?></td>
                <td class="c"><?= ovr_badge($p['ovr'], 'sm') ?></td>
                <td class="c hide-sm"><?= $pot !== '' ? '<span class="grade ' . ($gradeCls[$pot] ?? '') . '" title="Estimativa dos olheiros">' . e($pot) . '</span>' : $dash ?></td>
                <td class="num hide-sm"><?= (int) $p['age'] ?></td>
                <td class="num hide-sm"><?= $g ?: $dash ?></td>
                <td class="num"><?= $g ? '<b>' . number_format($p['_pts'], 1) . '</b>' : $dash ?></td>
                <td class="num hide-sm"><?= $g ? number_format($p['_reb'], 1) : $dash ?></td>
                <td class="num hide-sm"><?= $g ? number_format($p['_ast'], 1) : $dash ?></td>
                <td class="num hide-sm"><?= $g ? number_format($p['_stl'], 1) : $dash ?></td>
                <td class="num hide-sm"><?= $g ? number_format($p['_blk'], 1) : $dash ?></td>
                <td class="num hide-sm"><?= (int) $p['s_fga'] ? number_format($p['_fgp'], 1) : $dash ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>

      <?php if ($gp): ?>
      <section class="panel">
        <?= panel_head('Médias do time', ['icon' => 'graph-up', 'meta' => 'Elenco atual, por jogo']) ?>
        <div class="stats">
          <?= stat_tile('Rebotes', number_format($sum['reb'] / $gp, 1)) ?>
          <?= stat_tile('Assistências', number_format($sum['ast'] / $gp, 1)) ?>
          <?= stat_tile('Roubos', number_format($sum['stl'] / $gp, 1)) ?>
          <?= stat_tile('Tocos', number_format($sum['blk'] / $gp, 1)) ?>
          <?= stat_tile('Arremessos', $sum['fga'] ? number_format($sum['fgm'] / $sum['fga'] * 100, 1) . '%' : '—', 'de quadra') ?>
          <?= stat_tile('Bolas de 3', $sum['tpa'] ? number_format($sum['tpm'] / $sum['tpa'] * 100, 1) . '%' : '—', 'aproveitamento') ?>
        </div>
      </section>
      <?php endif; ?>
    </div>

    <div class="stack">
      <section class="panel tm-sched">
        <?= panel_head('Jogos', ['icon' => 'calendar3', 'more' => ['Calendário', url('schedule')]]) ?>
        <?php if (!$sched): ?>
          <?= empty_state('Sem jogos marcados.', '', 'calendar-x') ?>
        <?php else: ?>
          <?php if ($upcoming): ?>
            <div class="sub-h first">Próximos</div>
            <?php render_team_schedule(array_slice($upcoming, 0, 5)); ?>
          <?php endif; ?>
          <?php if ($recent): ?>
            <div class="sub-h<?= $upcoming ? '' : ' first' ?>">Últimos resultados</div>
            <?php render_team_schedule($recent); ?>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="panel">
        <?= panel_head('Relatório dos olheiros', ['icon' => 'binoculars-fill']) ?>
        <?php render_scout_card($scout); ?>
      </section>

      <section class="panel">
        <?= panel_head('Escolhas de draft', ['icon' => 'mortarboard-fill', 'meta' => $picks ? (count($picks) === 1 ? '1 futura' : count($picks) . ' futuras') : '']) ?>
        <?php if ($picks): ?>
          <div class="row tm-picks">
            <?php foreach ($picks as $pk):
              $r1  = (int) $pk['round'] === 1;
              $via = (int) $pk['original_team_id'] !== (int) $pk['owner_team_id'] ? ' · via ' . $pk['orig_abbr'] : ''; ?>
              <?= chip(($r1 ? '1ª rodada ' : '2ª rodada ') . League::draftYearLabel((int) $pk['year']) . $via, $r1 ? 'info' : '') ?>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <?= empty_state('Nenhuma escolha futura.', 'Todas foram negociadas.', 'mortarboard') ?>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div>
<?php render_footer(); ?>
