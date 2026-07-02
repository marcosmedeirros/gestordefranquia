<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Folha Salarial');
$tbl = League::payrollTable();
$gmId = League::gmTeam();
?>
<h1 class="page-title">Folha Salarial & Contratos</h1>
<p class="legend">Cada jogador tem um <strong>salário anual</strong> e <strong>anos de contrato</strong>.
   A folha do time é a soma dos salários. Teto salarial <strong><?= money($tbl['cap']) ?></strong> ·
   linha do imposto de luxo <strong><?= money($tbl['tax_line']) ?></strong> ·
   teto rígido <strong><?= money($tbl['apron']) ?></strong>.</p>

<?php /* ── Folha do meu time + contratos do elenco ── */
if ($gmId):
  $myTeam = League::team($gmId);
  $roster = League::roster($gmId);
  usort($roster, fn($a,$b) => (int)$b['salary'] <=> (int)$a['salary']);
  $payroll = League::teamPayroll($gmId);
  $space   = League::capSpace($gmId);
  $tax     = League::luxuryTax($payroll);
  $status  = League::payrollStatus($payroll);
  $maxSal  = max(1, (int)($roster[0]['salary'] ?? 1));
?>
<section class="card" style="margin-bottom:16px;border-color:rgba(228,0,43,.3)">
  <div class="card-head"><h2>💰 Folha do <?= e($myTeam['city'].' '.$myTeam['name']) ?></h2>
    <span class="pay-status pay-<?= $status ?>">
      <?= ['ok'=>'Abaixo do teto / luxo','tax'=>'Pagando imposto de luxo','apron'=>'No teto rígido'][$status] ?>
    </span>
  </div>
  <div class="pay-summary">
    <div class="pay-box"><span><?= money($payroll) ?></span>Folha total</div>
    <div class="pay-box <?= $space < 0 ? 'neg' : 'pos' ?>"><span><?= ($space<0?'-':'')."".money(abs($space)) ?></span><?= $space<0?'Acima do teto':'Espaço de teto' ?></div>
    <div class="pay-box"><span><?= $tax > 0 ? money($tax) : '—' ?></span>Imposto de luxo</div>
    <div class="pay-box"><span><?= count($roster) ?>/15</span>Jogadores</div>
  </div>
  <table class="box-table" style="margin-top:12px">
    <thead><tr><th>Jogador</th><th>Pos</th><th class="hide-sm">Idade</th><th>OVR</th><th>Salário</th><th>Contrato</th><th class="hide-sm"></th></tr></thead>
    <tbody>
    <?php foreach ($roster as $p): ?>
      <tr>
        <td class="bx-name"><a href="<?= url('player',['id'=>$p['id']]) ?>"><?= e($p['name']) ?></a></td>
        <td><?= e($p['pos']) ?></td>
        <td class="num hide-sm"><?= (int)$p['age'] ?></td>
        <td><span class="ovr ovr-<?= $p['ovr']>=90?'elite':($p['ovr']>=80?'star':($p['ovr']>=75?'good':'role')) ?>"><?= (int)$p['ovr'] ?></span></td>
        <td class="num"><strong><?= money($p['salary']) ?></strong></td>
        <td class="num"><?= (int)$p['contract_years'] > 0 ? (int)$p['contract_years'].' ano'.((int)$p['contract_years']>1?'s':'') : '<span class="muted">expira</span>' ?></td>
        <td class="hide-sm" style="width:120px">
          <div class="sal-bar"><span style="width:<?= max(4, round((int)$p['salary']/$maxSal*100)) ?>%"></span></div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h2>Folha por equipe — Temporada <?= $tbl['season'] ?></h2></div>
  <table class="standings-table">
    <thead><tr><th>#</th><th>Time</th><th>Conf.</th><th>Folha</th><th>Espaço</th><th>Imposto</th><th>Status</th></tr></thead>
    <tbody>
    <?php $i = 1; foreach ($tbl['teams'] as $t):
      $stLabel = ['ok' => 'OK', 'tax' => 'Imposto', 'apron' => 'Teto rígido'][$t['status']]; ?>
      <tr<?= (int)$t['id'] === $gmId ? ' style="background:rgba(228,0,43,.07)"' : '' ?>>
        <td class="seed"><?= $i++ ?></td>
        <td><span class="dot" style="background:<?= e($t['color']) ?>"></span>
            <a href="<?= url('team',['id'=>$t['id']]) ?>"><?= e($t['city'].' '.$t['name']) ?></a></td>
        <td><?= $t['conf'] === 'E' ? 'Leste' : 'Oeste' ?></td>
        <td class="num"><strong><?= money($t['payroll']) ?></strong></td>
        <td class="num"><?= $t['space'] < 0 ? '<span class="muted">-'.money(abs($t['space'])).'</span>' : money($t['space']) ?></td>
        <td class="num"><?= $t['tax'] > 0 ? money($t['tax']) : '—' ?></td>
        <td><span class="pay-status pay-<?= $t['status'] ?>"><?= $stLabel ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php render_footer(); ?>
