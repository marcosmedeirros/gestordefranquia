<?php
require_once dirname(__DIR__) . '/helpers.php';

/** Cores de um lado do placar (--away-c, --away-soft...), tiradas do tema do time: a versão clara lê bem no fundo escuro. */
function game_side_vars(string $side, array $t): string
{
    $css = team_theme_style($t ?: null);
    $out = '';
    foreach (['team-bright' => 'c', 'team-soft' => 'soft', 'team-line' => 'line'] as $from => $to) {
        if (preg_match('/--' . $from . ':([^;]+)/', $css, $m)) $out .= '--' . $side . '-' . $to . ':' . $m[1] . ';';
    }
    return $out;
}

/** Um lado do placar: logo, sigla, nome, linha menor e o número grande (id sb-away / sb-home). */
function game_sb_side(string $side, array $g, array $t, string $name, bool $played, ?array $top): string
{
    $abbr = (string) $g[$side . '_abbr'];
    $pts  = (int) $g[$side . '_pts'];
    $opp  = (int) $g[($side === 'home' ? 'away' : 'home') . '_pts'];
    $cls  = 'sb-team ' . $side . ($played ? ($pts > $opp ? ' won' : ' lost') : '');
    if ($played) {
        $sub = $top ? 'Cestinha: ' . e((string) $top['name']) . ', ' . (int) $top['pts'] . ' pts' : '';
    } else {
        $sub = $t ? (int) $t['wins'] . '-' . (int) $t['losses'] . ' na temporada' : '';
    }
    return '<div class="' . $cls . '">'
        . '<a class="sb-id" href="' . url('team', ['id' => (int) $g[$side . '_id']]) . '">'
        . team_logo($abbr, (string) ($g[$side . '_color'] ?? '#333'), 'lg')
        . '<span class="sb-txt"><b class="sb-abbr">' . e($abbr) . '</b><span class="sb-name">' . e($name) . '</span>'
        . ($sub !== '' ? '<small class="sb-sub">' . $sub . '</small>' : '') . '</span></a>'
        . '<span class="sb-score" id="sb-' . $side . '">' . ($played ? $pts : 0) . '</span>'
        . '</div>';
}

/** Cabeçalho da tabela do box score (o live.js e o simcast.js montam a mesma tabela). */
function game_box_head(): string
{
    return '<thead><tr><th>Jogador</th><th class="num hide-sm" title="Minutos">MIN</th><th class="num" title="Pontos">PTS</th>'
        . '<th class="num" title="Rebotes">REB</th><th class="num" title="Assistências">AST</th>'
        . '<th class="num hide-sm" title="Roubos de bola">RB</th><th class="num hide-sm" title="Tocos">TOC</th>'
        . '<th class="num hide-sm" title="Erros">ERR</th><th class="num" title="Arremessos de quadra">FG</th>'
        . '<th class="num" title="Bolas de três">3P</th><th class="num hide-sm" title="Lances livres">LL</th></tr></thead>';
}

function game_box_cells(array $v): string
{
    return '<td class="num hide-sm">' . (int) round((float) $v['min']) . '</td><td class="num"><b>' . (int) $v['pts'] . '</b></td>'
        . '<td class="num">' . (int) $v['reb'] . '</td><td class="num">' . (int) $v['ast'] . '</td>'
        . '<td class="num hide-sm">' . (int) $v['stl'] . '</td><td class="num hide-sm">' . (int) $v['blk'] . '</td>'
        . '<td class="num hide-sm">' . (int) $v['tov'] . '</td>'
        . '<td class="num">' . (int) $v['fgm'] . '-' . (int) $v['fga'] . '</td><td class="num">' . (int) $v['tpm'] . '-' . (int) $v['tpa'] . '</td>'
        . '<td class="num hide-sm">' . (int) $v['ftm'] . '-' . (int) $v['fta'] . '</td>';
}

function renderBoxScore(int $gameId, array $g, ?array $box = null, array $names = []): void
{
    $box = $box ?? League::boxScore($gameId);
    $byTeam = [(int) $g['away_id'] => [], (int) $g['home_id'] => []];
    foreach ($box as $b) { $byTeam[(int) $b['team_id']][] = $b; }
    $cols = ['min', 'pts', 'reb', 'ast', 'stl', 'blk', 'tov', 'fgm', 'fga', 'tpm', 'tpa', 'ftm', 'fta'];
    echo '<div class="box-teams">';
    foreach (['away', 'home'] as $side) {
        $rows = $byTeam[(int) $g[$side . '_id']] ?? [];
        usort($rows, fn($a, $b) => $b['pts'] <=> $a['pts']);
        $tot = array_fill_keys($cols, 0);
        $name = $names[$side] ?? ($g[$side . '_city'] . ' ' . $g[$side . '_name']);
        echo '<div class="box-team ' . $side . '"><h3 class="box-team-h"><span class="box-dot"></span>' . e($name) . '</h3>';
        echo '<div class="table-wrap"><table class="tbl compact box-tbl">' . game_box_head() . '<tbody>';
        foreach ($rows as $b) {
            foreach ($cols as $c) $tot[$c] += (float) $b[$c];
            echo '<tr><td><a class="bx-p" href="' . url('player', ['id' => $b['player_id']]) . '"><b>' . e($b['name']) . '</b>'
                . '<span class="pos-tag">' . e($b['pos']) . '</span></a></td>' . game_box_cells($b) . '</tr>';
        }
        if (!$rows) echo '<tr><td colspan="11" class="dim">Sem estatísticas registradas.</td></tr>';
        echo '</tbody>';
        if ($rows) echo '<tfoot><tr><td>Total</td>' . game_box_cells($tot) . '</tr></tfoot>';
        echo '</table></div></div>';
    }
    echo '</div>';
}

$id = (int) ($_GET['id'] ?? 0);
$g = League::game($id);
if (!$g) {
    render_header('Jogo');
    page_head('Jogo não encontrado', ['eyebrow' => 'Liga', 'sub' => 'Esse jogo não existe neste save.']);
    echo empty_state('Nada por aqui.', 'Volte ao calendário e escolha outro jogo.', 'calendar-x');
    render_footer();
    exit;
}

$played       = (bool) $g['played'];
$isGmLive     = !$played && League::gmLiveGame($id) !== null;
$gm           = (int) League::gmTeam();
$isGmGame     = $gm > 0 && ((int) $g['home_id'] === $gm || (int) $g['away_id'] === $gm);
$phase        = League::phase();
$advancePhase = in_array($phase, ['regular', 'playin', 'playoffs'], true);
$advLabel     = ['playin' => 'Avançar o play-in', 'playoffs' => 'Avançar os playoffs'][$phase] ?? 'Avançar o dia';
$dateLabel    = League::dateLabel((int) $g['day']);
$series       = $g['stage'] === 'playoffs' ? League::seriesStatus((int) ($g['series_id'] ?? 0)) : null;
$stageName    = ['regular' => 'Temporada regular', 'playin' => 'Play-in', 'playoffs' => 'Playoffs'][$g['stage']] ?? ucfirst((string) $g['stage']);

$teams = ['away' => League::team((int) $g['away_id']) ?: [], 'home' => League::team((int) $g['home_id']) ?: []];
$names = [];
foreach ($teams as $s => $t) $names[$s] = $t ? teamFull($t) : $g[$s . '_city'] . ' ' . $g[$s . '_name'];

$box = $played ? League::boxScore($id) : [];
$topScorer = [];
foreach ($box as $b) { // o box já vem ordenado por pontos
    if (!isset($topScorer[(int) $b['team_id']])) $topScorer[(int) $b['team_id']] = $b;
}

$speedCtl = '<label class="sb-speed" for="speedSel"><span>Velocidade</span><select id="speedSel" class="input">'
    . '<option value="450">Lenta</option><option value="180" selected>Normal</option><option value="60">Rápida</option></select></label>';

// Jogo ao vivo do GM: o comando fica na tela e o botão Próxima some até o fim.
render_header($g['away_abbr'] . ' @ ' . $g['home_abbr'], ['dock' => !$isGmLive]);
?>
<div class="stack game-page" style="<?= e(game_side_vars('away', $teams['away']) . game_side_vars('home', $teams['home'])) ?>">

<section class="broadcast<?= $isGmGame ? ' mine' : '' ?>" aria-label="Placar">
  <div class="bc-bar">
    <span class="bc-stage"><?= bi($g['stage'] === 'regular' ? 'calendar3' : 'trophy-fill') ?><?= e($stageName) ?> · <?= e($dateLabel) ?></span>
    <span class="row bc-tags">
      <?php if ($series): ?>
        <?= chip((string) $series['round_name'], 'info') ?>
        <?php if (!$played): ?><?= chip('Jogo ' . min(7, (int) $series['game'])) ?><?php endif; ?>
        <span class="bc-lead"><?= e(ucfirst((string) $series['lead'])) ?></span>
        <?php if (!$played && $series['tag']): ?><?= chip((string) $series['tag'], 'warn', 'fire') ?><?php endif; ?>
      <?php endif; ?>
      <?php if ($isGmLive): ?><?= chip('Você no comando', 'team', 'joystick') ?>
      <?php elseif ($isGmGame): ?><?= chip('Seu jogo', 'team', 'person-check-fill') ?><?php endif; ?>
    </span>
  </div>

  <div class="scoreboard<?= $played ? ' is-final' : '' ?>" id="scoreboard"
       data-game="<?= $id ?>" data-home="<?= e($g['home_abbr']) ?>" data-away="<?= e($g['away_abbr']) ?>">
    <?= game_sb_side('away', $g, $teams['away'], $names['away'], $played, $topScorer[(int) $g['away_id']] ?? null) ?>
    <div class="sb-mid">
      <span class="sb-quarter" id="sb-quarter"><?= $played ? 'Encerrado' : 'Pré-jogo' ?></span>
      <span class="sb-clock" id="sb-clock"><?= $played ? 'FINAL' . ((int) $g['ot'] ? '/PR' . ((int) $g['ot'] > 1 ? (int) $g['ot'] : '') : '') : '12:00' ?></span>
    </div>
    <?= game_sb_side('home', $g, $teams['home'], $names['home'], $played, $topScorer[(int) $g['home_id']] ?? null) ?>
  </div>

  <?php if (!$isGmLive): ?>
  <div class="bc-foot">
    <?php if ($played): ?>
      <button type="button" class="btn" id="startBtn" data-replay="1"><?= bi('arrow-counterclockwise') ?>Reassistir lance a lance</button>
    <?php else: ?>
      <button type="button" class="btn btn-team" id="startBtn"><?= bi('play-fill') ?>Assistir ao jogo</button>
    <?php endif; ?>
    <?= $speedCtl ?>
  </div>
  <?php endif; ?>
</section>

<?php
// ── Estatísticas: box score (depois do jogo) ou quintetos com médias (antes). O JS troca o conteúdo no fim.
ob_start(); ?>
<section class="panel" id="boxCard">
  <div class="panel-head"><h2><?= bi('clipboard-data') ?><span id="boxTitle"><?= $played ? 'Box score' : 'Quintetos iniciais' ?></span></h2></div>
  <div id="boxContent">
    <?php if ($played): ?>
      <?php renderBoxScore($id, $g, $box, $names); ?>
    <?php else: ?>
      <p class="box-lead">Titulares de cada time, com as médias na temporada.</p>
      <div class="box-teams">
        <?php foreach (['away', 'home'] as $s):
          $starters = array_filter(League::roster((int) $g[$s . '_id']), fn($p) => (int) $p['is_starter']); ?>
        <div class="box-team <?= $s ?>">
          <h3 class="box-team-h"><span class="box-dot"></span><?= e($names[$s]) ?></h3>
          <div class="table-wrap">
            <table class="tbl compact">
              <thead><tr><th>Jogador</th><th class="c">OVR</th><th class="num" title="Pontos por jogo">PTS</th><th class="num" title="Rebotes por jogo">REB</th><th class="num" title="Assistências por jogo">AST</th></tr></thead>
              <tbody>
              <?php foreach ($starters as $p): ?>
                <tr>
                  <td><?= player_who($p, '', (string) ($g[$s . '_color'] ?? '#1a1a2e')) ?></td>
                  <td class="c"><?= ovr_badge($p['ovr'], 'sm') ?></td>
                  <td class="num"><?= avg($p['s_pts'] ?? 0, $p['gp'] ?? 0) ?></td>
                  <td class="num"><?= avg($p['s_reb'] ?? 0, $p['gp'] ?? 0) ?></td>
                  <td class="num"><?= avg($p['s_ast'] ?? 0, $p['gp'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$starters): ?><tr><td colspan="5" class="dim">Sem titulares definidos.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php $statsPanel = ob_get_clean(); ?>

<?php if ($isGmLive): ?>
<div class="grid game-duo">
  <section class="panel live-panel" id="livePanel">
    <div class="panel-head">
      <h2><?= bi('joystick') ?>Comando ao vivo</h2>
      <div class="row">
        <span class="chip team live-side" id="liveSide"></span>
        <span class="chip live-to" title="Tempos técnicos que ainda restam"><?= bi('stopwatch') ?>Tempos <b id="liveTimeouts">7</b></span>
      </div>
    </div>

    <div class="live-actions">
      <button type="button" class="btn btn-team btn-lg" id="liveNextBtn"><?= bi('play-fill') ?>Próximo quarto</button>
      <button type="button" class="btn btn-lg" id="liveAutoBtn"><?= bi('fast-forward-fill') ?>Simular até o fim</button>
    </div>
    <p class="live-hint" id="liveHint">Ajuste a tática e jogue um quarto por vez. Jogo apertado no 4º quarto vira clutch time.</p>

    <div class="continue-bar" id="continueBar" hidden>
      <?php if ($advancePhase): ?>
      <a class="btn btn-team btn-lg" href="<?= url('home', ['action' => 'advance', 'back' => url('home')]) ?>" data-busy="Simulando o dia"><?= bi('fast-forward-fill') ?><?= e($advLabel) ?></a>
      <?php endif; ?>
      <a class="btn btn-lg" href="#boxCard"><?= bi('clipboard-data') ?>Box score</a>
      <a class="btn btn-lg btn-ghost" href="<?= url('home') ?>"><?= bi('house-door-fill') ?>Central</a>
      <p class="cb-note"><?= $advancePhase ? 'Avançar simula os outros jogos da data e mostra o resumo do dia.' : 'O resultado já está salvo.' ?></p>
    </div>

    <details class="live-tactics" open>
      <summary><?= bi('sliders') ?>Tática do próximo quarto</summary>
      <div class="live-controls">
        <div class="field"><label for="ctrlOff">Ataque</label><select class="input" id="ctrlOff"></select></div>
        <div class="field"><label for="ctrlDef">Defesa</label><select class="input" id="ctrlDef"></select></div>
        <div class="field wide"><label for="ctrlDouble">Marcação dupla em</label><select class="input" id="ctrlDouble"><option value="0">Ninguém</option></select></div>
        <label class="live-toggle" for="ctrlTimeout">
          <input type="checkbox" id="ctrlTimeout">
          <span><b>Pedir tempo</b><small>Descansa o time e dá um gás neste quarto.</small></span>
        </label>
      </div>
    </details>
  </section>

  <section class="panel pbp-panel">
    <div class="panel-head"><h2><?= bi('broadcast') ?>Lance a lance</h2><div class="row"><?= $speedCtl ?></div></div>
    <div class="pbp-feed" id="pbpFeed">
      <div class="empty" id="pbpEmpty"><?= bi('mic-fill') ?><b>A bola ainda não subiu</b>Toque em Próximo quarto para começar o jogo.</div>
    </div>
  </section>
</div>

<div class="grid cols-main">
  <?= $statsPanel ?>
  <?php if ($isGmGame && !$played):
    $oppId = (int) $g['home_id'] === $gm ? (int) $g['away_id'] : (int) $g['home_id']; ?>
  <section class="panel">
    <?= panel_head('Scouting do adversário', ['icon' => 'binoculars-fill']) ?>
    <?php render_scout_card(League::scoutReport($oppId, $gm)); ?>
  </section>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="grid cols-main">
  <?= $statsPanel ?>
  <section class="panel pbp-panel">
    <div class="panel-head"><h2><?= bi('broadcast') ?>Lance a lance</h2></div>
    <div class="pbp-feed" id="pbpFeed">
      <?php if ($played): ?>
        <div class="empty" id="pbpEmpty"><?= bi('mic-fill') ?><b>Jogo encerrado</b>Toque em Reassistir para ver o jogo de novo, lance a lance.</div>
      <?php else: ?>
        <div class="empty" id="pbpEmpty"><?= bi('mic-fill') ?><b>O jogo ainda não começou</b>Toque em Assistir ao jogo para acompanhar lance a lance.</div>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php endif; ?>

</div>

<script>
window.GAME_API = "api.php?game=<?= $id ?>";
window.API_URL   = "<?= APP_BASE ?>/api.php";
window.GAME_ID = <?= $id ?>;
window.IS_GM_LIVE = <?= $isGmLive ? 'true' : 'false' ?>;
window.GAME_META = {
  home_abbr: <?= json_encode($g['home_abbr']) ?>, away_abbr: <?= json_encode($g['away_abbr']) ?>,
  home_color: <?= json_encode($g['home_color']) ?>, away_color: <?= json_encode($g['away_color']) ?>,
  home_name: <?= json_encode($names['home'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>, away_name: <?= json_encode($names['away'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
};
</script>
<script src="<?= e(asset_v($isGmLive ? 'assets/js/live.js' : 'assets/js/simcast.js')) ?>"></script>
<?php render_footer(); ?>
