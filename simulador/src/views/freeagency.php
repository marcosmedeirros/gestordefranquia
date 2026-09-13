<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Offseason.php';
render_header('Agentes Livres');

$phase = League::phase();
$isWindow = $phase === 'freeagency';
if (!Cap::signingOpen()) {
    echo '<section class="card"><div class="card-head"><h2>🖊️ Agentes Livres</h2></div>';
    echo '<p class="muted">Contratações fechadas nesta fase. Elas voltam na pré-temporada, na free agency e na temporada regular.</p></section>';
    render_footer();
    exit;
}

$gm = League::gmTeam();
$season = $isWindow ? (int) Database::meta('fa_season', League::season() + 1) : League::season();
$msg = $_GET['msg'] ?? null;
$fas = Offseason::freeAgents(80);
$gmTeam = $gm ? League::team($gm) : null;
$cap = $gm ? Cap::summary($gm) : null;
$rosterCount = $cap ? $cap['count'] : 0;
$signed = League::transactions($season, 200);
$signed = array_values(array_filter($signed, fn($x) => in_array($x['type'], ['free agency', 'dispensa'], true)));
$space = $cap ? max(0, $cap['space']) : 0;
?>
<?php if ($isWindow): ?>
<div class="champion-banner" style="background:linear-gradient(135deg,#0b6e4f,#13b87b)">
  🖊️ Free Agency — Temporada <?= $season ?>. Contrate agentes livres antes de iniciar a temporada.
  <a href="<?= url('draft', ['season' => $season]) ?>" style="color:#fff;text-decoration:underline;margin-left:8px">🎓 Ver resultado do Draft</a>
</div>
<?php else: ?>
<h1 class="page-title">✍️ Agentes Livres</h1>
<p class="legend">Jogadores sem time — dispensados pelos clubes para caber no teto ou sobras da última free agency. Qualquer time pode assinar,
   desde que o salário (tabela por OVR) <strong>caiba no espaço do teto</strong> e o elenco tenha vaga (máx. <?= Cap::ROSTER_MAX ?>).</p>
<?php endif; ?>

<?php if ($msg): ?><div class="cap-alert <?= str_starts_with($msg, '✅') ? 'cap-alert-ok' : 'cap-alert-danger' ?>"><?= e($msg) ?></div><?php endif; ?>

<?php if ($gmTeam && $cap): ?>
<div class="board-goal goal-<?= $cap['status'] === 'ok' ? 'andamento' : 'falhou' ?>">
  🏟️ <strong><?= e(teamFull($gmTeam)) ?></strong>
  <span class="goal-detail">Elenco <?= $rosterCount ?>/<?= Cap::ROSTER_MAX ?> · Folha <?= Cap::m($cap['payroll']) ?> · Teto <?= Cap::m($cap['cap_max']) ?> · Espaço <strong><?= Cap::m($space) ?></strong><?= $cap['status'] === 'under' ? ' · ' . Cap::m($cap['deficit']) . ' abaixo do piso' : '' ?></span>
  <span class="goal-badge"><?= $cap['status'] === 'over' ? 'acima do teto — nada cabe' : ($rosterCount >= Cap::ROSTER_MAX ? 'elenco cheio' : ($space < 2 * Cap::M ? 'sem espaço no teto' : ($space < 3 * Cap::M ? 'só salário mínimo cabe' : 'pode contratar'))) ?></span>
</div>
<?php else: ?>
<p class="muted">Você não está no modo GM — a IA cuidará das contratações. <a href="<?= url('gmselect') ?>">Assumir uma franquia →</a></p>
<?php endif; ?>

<div class="dashboard">
  <section class="card span2">
    <div class="card-head"><h2>Agentes Livres Disponíveis (<?= count($fas) ?>)</h2></div>
    <table class="box-table">
      <thead><tr><th>Jogador</th><th>Pos</th><th class="hide-sm">Idade</th><th>OVR</th><th>Salário</th><th class="hide-sm">Int</th><th class="hide-sm">3P</th><th class="hide-sm">Arm</th><th class="hide-sm">Reb</th><th class="hide-sm">Def</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($fas as $p):
        $sal = Cap::ovrSalary((int)$p['ovr']) * Cap::M;
        $fits = $gm && $rosterCount < Cap::ROSTER_MAX && $sal <= $space; ?>
        <tr>
          <td class="bx-name"><?= e($p['name']) ?></td>
          <td><?= e($p['pos']) ?></td>
          <td class="num hide-sm"><?= (int)$p['age'] ?></td>
          <td class="num"><span class="ovr ovr-<?= $p['ovr']>=90?'elite':($p['ovr']>=80?'star':($p['ovr']>=75?'good':'role')) ?>"><?= (int)$p['ovr'] ?></span></td>
          <td class="num"><strong><?= Cap::m($sal) ?></strong><span class="muted">/ano</span></td>
          <td class="num hide-sm grade <?= gradeClass($p['ins']) ?>"><?= grade($p['ins']) ?></td>
          <td class="num hide-sm grade <?= gradeClass($p['thr']) ?>"><?= grade($p['thr']) ?></td>
          <td class="num hide-sm grade <?= gradeClass($p['pmk']) ?>"><?= grade($p['pmk']) ?></td>
          <td class="num hide-sm grade <?= gradeClass($p['reb']) ?>"><?= grade($p['reb']) ?></td>
          <td class="num hide-sm grade <?= gradeClass($p['def']) ?>"><?= grade($p['def']) ?></td>
          <td>
            <?php if ($fits): ?>
              <a class="btn btn-sm btn-primary" href="<?= url('home', ['action'=>'sign-fa','fa'=>$p['id']]) ?>"
                 onclick="return confirm('Contratar <?= e($p['name']) ?> por <?= Cap::m($sal) ?>/ano?')">Contratar</a>
            <?php elseif ($gm): ?>
              <span class="btn btn-sm btn-disabled" title="<?= $rosterCount >= Cap::ROSTER_MAX ? 'Elenco cheio' : 'Não cabe no teto' ?>"><?= $rosterCount >= Cap::ROSTER_MAX ? 'sem vaga' : 'não cabe' ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$fas): ?><tr><td colspan="11" class="muted">Sem agentes livres disponíveis.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="card">
    <?php if ($isWindow): ?>
    <div class="card-head"><h2>Encerrar a janela</h2></div>
    <p class="legend">Ao concluir, a IA completa os elencos e a nova temporada começa. Você precisa estar <strong>dentro do teto</strong> para começar.</p>
    <a class="btn btn-primary" href="<?= url('home', ['action'=>'finish-fa']) ?>"
       onclick="return confirm('Encerrar a Free Agency e iniciar a temporada <?= $season ?>?')">🏁 Concluir e iniciar temporada</a>
    <?php else: ?>
    <div class="card-head"><h2>Abrir espaço</h2></div>
    <p class="legend">Sem espaço no teto? Dispense ou troque alguém em <a href="<?= url('cap') ?>">Folha &amp; Cap</a>. O salário do dispensado sai da folha na hora.</p>
    <?php endif; ?>

    <div class="card-head" style="margin-top:18px"><h2>Movimentações recentes</h2></div>
    <div class="news-list">
      <?php foreach (array_slice($signed, 0, 14) as $s): ?>
        <div class="nl-item"><?= e($s['description']) ?></div>
      <?php endforeach; ?>
      <?php if (!$signed): ?><p class="muted">Nenhuma movimentação ainda.</p><?php endif; ?>
    </div>
  </section>
</div>
<?php render_footer(); ?>
