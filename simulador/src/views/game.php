<?php
require_once dirname(__DIR__) . '/helpers.php';
$id = (int) ($_GET['id'] ?? 0);
$g = League::game($id);
if (!$g) { render_header('Jogo'); echo '<p>Jogo não encontrado.</p>'; render_footer(); exit; }
render_header('Jogo ' . $g['away_abbr'] . ' @ ' . $g['home_abbr']);
$played = (bool) $g['played'];
$isGmLive = !$played && League::gmLiveGame($id) !== null;
$gm = League::gmTeam();
$isGmGame = $gm && ((int) $g['home_id'] === $gm || (int) $g['away_id'] === $gm);
$advancePhase = in_array(League::phase(), ['regular', 'playin', 'playoffs'], true);
$dateLabel = League::dateLabel((int) $g['day']);
?>
<p class="muted" style="margin:0 0 8px">📅 <?= e($dateLabel) ?> · <?= e(['regular'=>'Temporada Regular','playin'=>'Play-In','playoffs'=>'Playoffs'][League::phase()] ?? '') ?></p>
<div class="scoreboard" id="scoreboard"
     data-game="<?= $id ?>"
     data-home="<?= e($g['home_abbr']) ?>" data-away="<?= e($g['away_abbr']) ?>">
  <div class="sb-team away" style="--c:<?= e($g['away_color']) ?>">
    <div class="sb-abbr"><?= e($g['away_abbr']) ?></div>
    <div class="sb-city"><?= e($g['away_city']) ?> <?= e($g['away_name']) ?></div>
    <div class="sb-score" id="sb-away"><?= $played ? $g['away_pts'] : '0' ?></div>
  </div>
  <div class="sb-mid">
    <div class="sb-clock" id="sb-clock"><?= $played ? 'FINAL' . ($g['ot'] ? '/PR'.($g['ot']>1?$g['ot']:'') : '') : '—' ?></div>
    <div class="sb-quarter" id="sb-quarter"><?= $played ? '' : 'Pré-jogo' ?></div>
    <?php if (!$isGmLive && !$played): ?>
      <button class="btn btn-primary" id="startBtn">▶ Iniciar Simcast</button>
    <?php elseif (!$isGmLive): ?>
      <button class="btn" id="startBtn" data-replay="1">↻ Reassistir Simcast</button>
    <?php endif; ?>
    <div class="sb-speed">
      Velocidade
      <select id="speedSel">
        <option value="450">Lenta</option>
        <option value="180" selected>Normal</option>
        <option value="60">Rápida</option>
      </select>
    </div>
  </div>
  <div class="sb-team home" style="--c:<?= e($g['home_color']) ?>">
    <div class="sb-abbr"><?= e($g['home_abbr']) ?></div>
    <div class="sb-city"><?= e($g['home_city']) ?> <?= e($g['home_name']) ?></div>
    <div class="sb-score" id="sb-home"><?= $played ? $g['home_pts'] : '0' ?></div>
  </div>
</div>

<?php if ($isGmGame && $advancePhase): ?>
<div class="continue-bar" id="continueBar" style="<?= $played ? '' : 'display:none' ?>">
  <a class="btn btn-primary" href="<?= url('home', ['action'=>'advance','back'=>url('home')]) ?>">✅ Finalizar e avançar (simula demais jogos do dia)</a>
  <a class="btn" href="<?= url('home') ?>">← Voltar ao painel</a>
</div>
<?php endif; ?>

<?php if ($isGmLive): ?>
<section class="card live-panel" id="livePanel">
  <div class="card-head"><h2>🎮 Comando ao vivo <span class="muted" id="liveSide"></span></h2>
    <span class="live-timeouts">⏱️ Tempos: <strong id="liveTimeouts">7</strong></span>
  </div>
  <div class="live-actions">
    <button class="btn btn-primary btn-lg" id="liveNextBtn">▶ Simular Quarto</button>
    <button class="btn btn-lg" id="liveAutoBtn">⏭ Simular Jogo inteiro</button>
    <span class="live-hint" id="liveHint">Defina a tática abaixo e simule quarto a quarto. No 4º quarto apertado, é o Clutch Time!</span>
  </div>
  <details class="live-tactics" open>
    <summary>⚙️ Ajustes táticos do próximo quarto</summary>
    <div class="live-controls">
      <label>Foco ofensivo<select id="ctrlOff"></select></label>
      <label>Esquema defensivo<select id="ctrlDef"></select></label>
      <label>Marcação dupla em<select id="ctrlDouble"><option value="0">— ninguém —</option></select></label>
      <label class="ctrl-check"><input type="checkbox" id="ctrlTimeout"> Pedir tempo (alívio de cansaço)</label>
    </div>
  </details>
</section>
<?php endif; ?>

<?php if ($isGmGame && !$played):
  $oppId = ((int) $g['home_id'] === $gm) ? (int) $g['away_id'] : (int) $g['home_id'];
?>
<section class="card scout-card">
  <div class="card-head"><h2>🔍 Scouting — <?= e(((int)$g['home_id']===$gm) ? $g['away_city'].' '.$g['away_name'] : $g['home_city'].' '.$g['home_name']) ?></h2></div>
  <?php render_scout_card(League::scoutReport($oppId, $gm)); ?>
</section>
<?php endif; ?>

<?php
// Get starting lineups for preview (season averages)
$awayRoster = League::roster((int)$g['away_id']);
$homeRoster  = League::roster((int)$g['home_id']);
$awayStarters = array_filter($awayRoster, fn($p) => (int)$p['is_starter']);
$homeStarters = array_filter($homeRoster,  fn($p) => (int)$p['is_starter']);
?>

<!-- ── ESTATÍSTICAS: Box Score (pós-jogo) ou Médias da Temporada (pré-jogo) ── -->
<section class="card" id="statsSection">
  <div class="card-head">
    <h2 id="statsTitle"><?= $played ? 'Box Score' : 'Quintetos Iniciais — Médias na Temporada' ?></h2>
    <?php if ($played && $isGmGame && $advancePhase): ?>
      <a class="btn btn-primary btn-sm" href="<?= url('home', ['action'=>'advance','back'=>url('home')]) ?>">✅ Avançar →</a>
    <?php endif; ?>
  </div>

  <div id="statsContent">
    <?php if ($played): ?>
      <?php renderBoxScore($id, $g); ?>
    <?php else: ?>
      <!-- Pré-jogo: exibe quintetos com médias -->
      <div class="game-lineups">
        <?php foreach ([
          [$g['away_abbr'], $g['away_city'].' '.$g['away_name'], $g['away_color'], $awayStarters],
          [$g['home_abbr'], $g['home_city'].' '.$g['home_name'], $g['home_color'], $homeStarters]
        ] as [$abbr, $name, $color, $starters]): ?>
        <div class="gl-team">
          <div class="gl-header" style="border-left:3px solid <?= e($color) ?>">
            <strong><?= e($name) ?></strong>
            <span class="muted" style="font-size:12px;margin-left:8px"><?= e($abbr) ?></span>
          </div>
          <table class="box-table" style="margin-top:6px">
            <thead><tr><th>Jogador</th><th>Pos</th><th>OVR</th><th class="num">PPG</th><th class="num">RPG</th><th class="num">APG</th></tr></thead>
            <tbody>
              <?php foreach ($starters as $p): ?>
              <tr>
                <td class="bx-name">
                  <a href="<?= url('player',['id'=>$p['id']]) ?>"><?= e($p['name']) ?></a>
                </td>
                <td><?= e($p['pos']) ?></td>
                <td><span class="ovr ovr-<?= $p['ovr']>=90?'elite':($p['ovr']>=80?'star':($p['ovr']>=75?'good':'role')) ?>"><?= $p['ovr'] ?></span></td>
                <td class="num"><?= avg($p['s_pts']??0, $p['gp']??0) ?></td>
                <td class="num"><?= avg($p['s_reb']??0, $p['gp']??0) ?></td>
                <td class="num"><?= avg($p['s_ast']??0, $p['gp']??0) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ── SIMCAST (colapsível quando jogo já foi jogado) ── -->
<?php if ($played): ?>
<details class="card" style="margin-top:12px">
  <summary style="cursor:pointer;padding:4px 0;font-weight:700;font-size:13px;color:var(--muted)">📻 Lance a lance (Simcast)</summary>
  <div class="pbp-feed" id="pbpFeed" style="margin-top:10px;max-height:300px">
    <p class="muted" id="pbpEmpty">Não disponível após a simulação.</p>
  </div>
</details>
<?php else: ?>
<section class="card" style="margin-top:12px">
  <div class="card-head"><h2>📻 Simcast — Lance a lance</h2></div>
  <div class="pbp-feed" id="pbpFeed">
    <p class="muted" id="pbpEmpty"><?= $isGmLive ? 'Clique em <strong>Simular Quarto</strong> para começar.' : 'Clique em <strong>▶ Iniciar Simcast</strong> para assistir.' ?></p>
  </div>
</section>
<?php endif; ?>

<script>
window.GAME_API = "api.php?game=<?= $id ?>";
window.API_URL   = "<?= APP_BASE ?>/api.php";
window.GAME_ID = <?= $id ?>;
window.IS_GM_LIVE = <?= $isGmLive ? 'true' : 'false' ?>;
window.GAME_META = {
  home_abbr: <?= json_encode($g['home_abbr']) ?>, away_abbr: <?= json_encode($g['away_abbr']) ?>,
  home_color: <?= json_encode($g['home_color']) ?>, away_color: <?= json_encode($g['away_color']) ?>
};
</script>
<?php if ($isGmLive): ?>
<script src="assets/js/live.js"></script>
<?php else: ?>
<script src="assets/js/simcast.js"></script>
<?php endif; ?>
<?php render_footer(); ?>

<?php
function renderBoxScore(int $gameId, array $g): void
{
    $box = League::boxScore($gameId);
    $byTeam = [(int)$g['away_id'] => [], (int)$g['home_id'] => []];
    foreach ($box as $b) { $byTeam[(int)$b['team_id']][] = $b; }
    foreach ([[(int)$g['away_id'], $g['away_city'].' '.$g['away_name']], [(int)$g['home_id'], $g['home_city'].' '.$g['home_name']]] as [$tid, $tname]) {
        echo '<h3 class="box-team">' . e($tname) . '</h3>';
        echo '<table class="box-table"><thead><tr><th>Jogador</th><th>MIN</th><th>PTS</th><th>REB</th><th>AST</th><th>RB</th><th>TO</th><th>FG</th><th>3P</th><th>LL</th></tr></thead><tbody>';
        $rows = $byTeam[$tid] ?? [];
        usort($rows, fn($a,$b)=>$b['pts']<=>$a['pts']);
        foreach ($rows as $b) {
            echo '<tr>';
            echo '<td class="bx-name"><a href="'.url('player',['id'=>$b['player_id']]).'">'.e($b['name']).'</a> <span class="muted">'.e($b['pos']).'</span></td>';
            echo '<td class="num">'.(int)$b['min'].'</td>';
            echo '<td class="num"><strong>'.$b['pts'].'</strong></td>';
            echo '<td class="num">'.$b['reb'].'</td>';
            echo '<td class="num">'.$b['ast'].'</td>';
            echo '<td class="num">'.($b['stl']+0).'/'.($b['blk']+0).'</td>';
            echo '<td class="num">'.$b['tov'].'</td>';
            echo '<td class="num">'.$b['fgm'].'-'.$b['fga'].'</td>';
            echo '<td class="num">'.$b['tpm'].'-'.$b['tpa'].'</td>';
            echo '<td class="num">'.$b['ftm'].'-'.$b['fta'].'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
?>
