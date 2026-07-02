<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Offseason.php';
render_header('Free Agency');

$phase = League::phase();
if ($phase !== 'freeagency') {
    echo '<section class="card"><div class="card-head"><h2>🖊️ Free Agency</h2></div>';
    echo '<p class="muted">A janela de Free Agency não está aberta no momento. Ela ocorre na entressafra, após o Draft.</p></section>';
    render_footer();
    exit;
}

$gm = League::gmTeam();
$season = (int) Database::meta('fa_season', League::season() + 1);
$msg = $_GET['msg'] ?? null;
$fas = Offseason::freeAgents(80);
$gmTeam = $gm ? League::team($gm) : null;
$roster = $gm ? League::roster($gm) : [];
$rosterCount = 0;
foreach ($roster as $r) { if (!($r['retired'] ?? 0)) $rosterCount++; }
$signed = League::transactions($season, 200);
$signed = array_values(array_filter($signed, fn($x) => $x['type'] === 'free agency'));
?>
<div class="champion-banner" style="background:linear-gradient(135deg,#0b6e4f,#13b87b)">
  🖊️ Free Agency — Temporada <?= $season ?>. Contrate agentes livres antes de iniciar a temporada.
</div>

<?php if ($msg): ?><div class="injury-note" style="background:#10371f;border-color:#1f6b3a;color:#9bffc0"><?= e($msg) ?></div><?php endif; ?>

<?php if ($gmTeam): ?>
<div class="board-goal goal-andamento">
  🏟️ <strong><?= e(teamFull($gmTeam)) ?></strong>
  <span class="goal-detail">Elenco: <?= $rosterCount ?>/15 jogadores · Folha <?= money(League::teamPayroll($gm)) ?> · Espaço <?= money(max(0, League::capSpace($gm))) ?></span>
  <span class="goal-badge"><?= $rosterCount >= 15 ? 'elenco cheio' : 'pode contratar' ?></span>
</div>
<?php else: ?>
<p class="muted">Você não está no modo GM — a IA cuidará das contratações. <a href="<?= url('gmselect') ?>">Assumir uma franquia →</a></p>
<?php endif; ?>

<div class="dashboard">
  <section class="card span2">
    <div class="card-head"><h2>Agentes Livres Disponíveis (<?= count($fas) ?>)</h2></div>
    <table class="box-table">
      <thead><tr><th>Jogador</th><th>Pos</th><th>Idade</th><th>OVR</th><th>Pede</th><th>Pot</th><th>Int</th><th>3P</th><th>Arm</th><th>Reb</th><th>Def</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($fas as $p): ?>
        <tr>
          <td class="bx-name"><?= e($p['name']) ?></td>
          <td><?= e($p['pos']) ?></td>
          <td class="num"><?= (int)$p['age'] ?></td>
          <td class="num"><span class="ovr ovr-<?= $p['ovr']>=90?'elite':($p['ovr']>=80?'star':($p['ovr']>=75?'good':'role')) ?>"><?= (int)$p['ovr'] ?></span></td>
          <td class="num"><strong><?= money($p['salary'] ?: Database::salaryForOvr((int)$p['ovr'],(int)$p['age'])) ?></strong>/ano</td>
          <td class="num"><?= (int)$p['potential'] ?></td>
          <td class="num grade <?= gradeClass($p['ins']) ?>"><?= grade($p['ins']) ?></td>
          <td class="num grade <?= gradeClass($p['thr']) ?>"><?= grade($p['thr']) ?></td>
          <td class="num grade <?= gradeClass($p['pmk']) ?>"><?= grade($p['pmk']) ?></td>
          <td class="num grade <?= gradeClass($p['reb']) ?>"><?= grade($p['reb']) ?></td>
          <td class="num grade <?= gradeClass($p['def']) ?>"><?= grade($p['def']) ?></td>
          <td>
            <?php if ($gm && $rosterCount < 15): ?>
              <a class="btn btn-sm btn-primary" href="<?= url('home', ['action'=>'sign-fa','fa'=>$p['id']]) ?>">Contratar</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$fas): ?><tr><td colspan="12" class="muted">Sem agentes livres disponíveis.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="card">
    <div class="card-head"><h2>Encerrar a janela</h2></div>
    <p class="legend">Ao concluir, a IA completa os elencos vazios e a nova temporada começa.
       Fique atento à folha salarial — assinaturas acima do teto pagam imposto de luxo.</p>
    <a class="btn btn-primary" href="<?= url('home', ['action'=>'finish-fa']) ?>"
       onclick="return confirm('Encerrar a Free Agency e iniciar a temporada <?= $season ?>?')">🏁 Concluir e iniciar temporada</a>

    <div class="card-head" style="margin-top:18px"><h2>Contratações recentes</h2></div>
    <div class="news-list">
      <?php foreach (array_slice($signed, 0, 12) as $s): ?>
        <div class="nl-item"><?= e($s['description']) ?></div>
      <?php endforeach; ?>
      <?php if (!$signed): ?><p class="muted">Nenhuma contratação ainda.</p><?php endif; ?>
    </div>
  </section>
</div>
<?php render_footer(); ?>
