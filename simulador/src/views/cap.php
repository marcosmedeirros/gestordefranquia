<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Folha & Cap');
$gmId   = League::gmTeam();
$phase  = League::phase();
$tbl    = Cap::leagueTable();
$capM   = Cap::max();
$floor  = Cap::floor();
$avgM   = (int) Database::meta('cap_avg_m', 0);
$dl     = Cap::deadlineDay();
$day    = League::currentDay();
$err    = $_GET['err'] ?? '';
$msg    = $_GET['msg'] ?? '';
$viewId = (int) ($_GET['team'] ?? ($gmId ?: ($tbl[0]['id'] ?? 0)));
$s      = $viewId ? Cap::summary($viewId) : null;
$team   = $viewId ? League::team($viewId) : null;
$isMine = $gmId && $viewId === $gmId;
$stLabel = ['ok' => 'Dentro do teto', 'over' => 'ACIMA DO TETO', 'under' => 'ABAIXO DO PISO'];
$fmtM = fn($v) => Cap::m((int) $v);
?>
<div class="card-head page" style="margin-bottom:12px">
  <h1 class="page-title">💰 Folha Salarial &amp; Salary Cap</h1>
  <form method="get" style="margin:0">
    <input type="hidden" name="p" value="cap">
    <select name="team" onchange="this.form.submit()" class="season-select">
      <?php foreach (League::allTeams() as $t): ?>
        <option value="<?= $t['id'] ?>" <?= (int)$t['id'] === $viewId ? 'selected' : '' ?>><?= e(teamFull($t)) ?><?= (int)$t['id'] === $gmId ? ' (meu time)' : '' ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($err): ?><div class="cap-alert cap-alert-danger">⛔ <?= e($err) ?></div><?php endif; ?>
<?php if ($gmId && Cap::inGrace()): ?><div class="cap-alert cap-alert-ok">🕊️ Carência: seu save é anterior ao Salary Cap. Até o fim da temporada <?= Cap::graceSeason() ?> a liga não trava o calendário nem pune pelo piso — use esse tempo para se adequar.</div><?php endif; ?>
<?php if ($msg): ?><div class="cap-alert cap-alert-ok"><?= e($msg) ?></div><?php endif; ?>

<p class="legend">As regras da <strong>FBA ELITE</strong>: o salário de cada jogador vem <strong>só do OVR</strong> (99 = $60M … 78 = $3M, 77 ou menos = $2M),
   calouro entra pela <em>rookie scale</em> no 1º ano, prêmio vira bônus na temporada seguinte. Teto desta liga: <strong><?= $fmtM($capM) ?></strong>
   · Piso: <strong><?= $fmtM($floor) ?></strong><?= $avgM ? " · folha média na instalação: {$avgM}M" : '' ?>.
   Todo time precisa estar entre o piso e o teto até a <strong>Trade Deadline (dia <?= $dl ?>)</strong>; depois dela não há mais trocas.
   <?php if ($phase === 'regular' && $day <= $dl): ?><span class="cap-dl-pill">⏰ faltam <?= $dl - $day ?> dias</span><?php endif; ?>
</p>

<?php if ($s && $team): ?>
<section class="card cap-card status-<?= $s['status'] ?>">
  <div class="card-head">
    <h2><?= team_logo($team['abbr'], $team['primary_color'], 'sm') ?> <?= e(teamFull($team)) ?><?= $isMine ? ' — sua franquia' : '' ?></h2>
    <span class="pay-status pay-<?= $s['status'] ?>"><?= $stLabel[$s['status']] ?></span>
  </div>
  <div class="pay-summary">
    <div class="pay-box"><span><?= $fmtM($s['payroll']) ?></span>Folha total</div>
    <div class="pay-box"><span><?= $fmtM($s['cap_max']) ?></span>Teto do time<?= $s['flex_total'] ? '<small>base ' . $fmtM($s['cap_base']) . ' + Cap Flex ' . $s['flex_total'] . 'M</small>' : '<small>sem Cap Flex</small>' ?></div>
    <div class="pay-box <?= $s['space'] < 0 ? 'neg' : 'pos' ?>"><span><?= ($s['space'] < 0 ? '−' : '') . $fmtM(abs($s['space'])) ?></span><?= $s['space'] < 0 ? 'Acima do teto' : 'Espaço no teto' ?></div>
    <div class="pay-box <?= $s['status'] === 'under' ? 'neg' : '' ?>"><span><?= $fmtM($floor) ?></span>Piso<?= $s['status'] === 'under' ? '<small>faltam ' . $fmtM($s['deficit']) . '</small>' : '' ?></div>
    <div class="pay-box"><span><?= $s['count'] ?>/<?= Cap::ROSTER_MAX ?></span>Jogadores<small>mínimo <?= Cap::ROSTER_MIN ?></small></div>
  </div>

  <?php if ($isMine): ?>
    <div class="cap-suggestions">
      <?php foreach (Cap::suggestions($s) as $sg): ?>
        <div class="cap-sg cap-sg-<?= $sg['type'] ?>"><?= e($sg['text']) ?></div>
      <?php endforeach; ?>
    </div>
    <div class="cap-actions">
      <?php if (Cap::tradesOpen()): ?><a class="btn btn-primary" href="<?= url('trades') ?>">⇄ Central de Trocas</a><?php else: ?><span class="btn btn-disabled" title="Janela de trocas fechada">⇄ Trocas fechadas</span><?php endif; ?>
      <?php if (Cap::signingOpen()): ?><a class="btn" href="<?= url('freeagency') ?>">✍️ Agentes livres</a><?php endif; ?>
      <a class="btn" href="<?= url('lineup') ?>">📋 Escalação</a>
    </div>
  <?php endif; ?>

  <table class="box-table" style="margin-top:12px">
    <thead><tr>
      <th>Jogador</th><th>Pos</th><th class="hide-sm">Idade</th><th>OVR</th><th>Salário</th><th class="hide-sm">Origem</th><th class="hide-sm">Vínculo</th><th></th>
    </tr></thead>
    <tbody>
    <?php $maxSal = max(1, (int) ($s['roster'][0]['salary'] ?? 1));
    foreach ($s['roster'] as $p):
      $src = $p['source'] === 'rookie' ? 'Rookie scale (pick ' . ($p['draft_round'] == 1 ? '#' . (int)$p['draft_pos'] : '2ª rodada') . ')' : 'Tabela OVR ' . (int)$p['ovr'];
      if ($p['bonus_m']) $src .= ' + bônus de prêmio ' . $p['bonus_m'] . 'M';
    ?>
      <tr class="<?= $p['injury_games'] ? 'row-injured' : '' ?>">
        <td class="bx-name">
          <a href="<?= url('player',['id'=>$p['id']]) ?>"><?= e($p['name']) ?></a>
          <?php if ($p['flex_counted']): ?><span class="cap-tag cap-tag-flex" title="Draftado por este time: soma <?= $p['flex'] ?>M no teto">FLEX +<?= $p['flex'] ?>M</span>
          <?php elseif ($p['flex'] > 0): ?><span class="cap-tag" title="Elegível ao Cap Flex, mas só 2 contam">flex</span><?php endif; ?>
          <?php if ($p['source'] === 'rookie'): ?><span class="cap-tag cap-tag-rookie">calouro</span><?php endif; ?>
          <?php if ($p['bonus_m']): ?><span class="cap-tag cap-tag-bonus">🏅 +<?= $p['bonus_m'] ?>M</span><?php endif; ?>
        </td>
        <td><?= e($p['pos']) ?></td>
        <td class="num hide-sm"><?= (int)$p['age'] ?></td>
        <td><span class="ovr ovr-<?= $p['ovr']>=90?'elite':($p['ovr']>=80?'star':($p['ovr']>=75?'good':'role')) ?>"><?= (int)$p['ovr'] ?></span></td>
        <td class="num"><strong><?= $fmtM($p['salary']) ?></strong>
          <div class="sal-bar only-inline"><span style="width:<?= max(4, round((int)$p['salary']/$maxSal*100)) ?>%"></span></div></td>
        <td class="hide-sm muted" style="font-size:11px"><?= e($src) ?></td>
        <td class="num hide-sm"><?= (int)$p['contract_years'] > 0 ? (int)$p['contract_years'].' ano'.((int)$p['contract_years']>1?'s':'') : '<span class="muted">expira</span>' ?></td>
        <td style="white-space:nowrap">
          <?php if ($isMine): ?>
            <?php if ((int)$p['contract_years'] <= 1): $dem = League::resignDemand($p); ?>
              <a class="btn btn-sm btn-primary" href="<?= url('home', ['action'=>'resign','pid'=>$p['id'],'choice'=>'accept','back'=>'cap']) ?>"
                 onclick="return confirm('Renovar <?= e($p['name']) ?> por <?= $dem['years'] ?> anos? O salário segue a tabela por OVR (<?= $fmtM($dem['salary']) ?>/ano hoje).')">✍️ Renovar <?= $dem['years'] ?>a</a>
            <?php endif; ?>
            <a class="btn btn-sm" href="<?= url('home', ['action'=>'release','pid'=>$p['id'],'back'=>'cap']) ?>"
               onclick="return confirm('Dispensar <?= e($p['name']) ?>? Ele vira agente livre e o salário de <?= $fmtM($p['salary']) ?> sai da folha na hora.')">🚪 Dispensar</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h2>Folha por equipe — Temporada <?= League::season() ?></h2>
    <span class="muted" style="font-size:11px">Teto <?= $fmtM($capM) ?> (+ Cap Flex de cada time) · Piso <?= $fmtM($floor) ?></span></div>
  <table class="standings-table">
    <thead><tr><th>#</th><th>Time</th><th class="hide-sm">Conf.</th><th>Folha</th><th>Teto</th><th>Espaço</th><th class="hide-sm">Elenco</th><th>Status</th></tr></thead>
    <tbody>
    <?php $i = 1; foreach ($tbl as $t): ?>
      <tr<?= (int)$t['id'] === $gmId ? ' style="background:rgba(228,0,43,.07)"' : '' ?>>
        <td class="seed"><?= $i++ ?></td>
        <td><span class="dot" style="background:<?= e($t['color']) ?>"></span>
            <a href="<?= url('cap',['team'=>$t['id']]) ?>"><?= e($t['city'].' '.$t['name']) ?></a></td>
        <td class="hide-sm"><?= $t['conf'] === 'E' ? 'Leste' : 'Oeste' ?></td>
        <td class="num"><strong><?= $fmtM($t['payroll']) ?></strong></td>
        <td class="num"><?= $fmtM($t['cap_max']) ?><?= $t['flex'] ? ' <span class="muted" style="font-size:10px">(+' . $t['flex'] . ' flex)</span>' : '' ?></td>
        <td class="num"><?= $t['space'] < 0 ? '<span class="neg-txt">−'.$fmtM(abs($t['space'])).'</span>' : $fmtM($t['space']) ?></td>
        <td class="num hide-sm"><?= $t['count'] ?></td>
        <td><span class="pay-status pay-<?= $t['status'] ?>"><?= ['ok'=>'OK','over'=>'Acima','under'=>'Abaixo do piso'][$t['status']] ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="card">
  <div class="card-head"><h2>📜 Como funciona</h2></div>
  <div class="cap-rules">
    <div><strong>Salário por OVR.</strong> 99→60M · 98→56 · 97→52 · 96→48 · 95→44 · 94→40 · 93→36 · 92→32 · 91→29 · 90→26 · 89→23 · 88→20 · 87→18 · 86→16 · 85→14 · 84→12 · 83→10 · 82→8 · 81→6 · 80→5 · 79→4 · 78→3 · até 77→2M (mínimo de veterano). Quando o OVR muda na entressafra, o salário muda junto.</div>
    <div><strong>Rookie scale (só no 1º ano).</strong> 1ª rodada: picks 1–3 = 18M · 4–8 = 14M · 9–12 = 12M · 13–16 = 8M · 17–22 = 5M · 23+ = 3M. 2ª rodada: 2M. No 2º ano o calouro passa pra tabela de OVR.</div>
    <div><strong>Cap Flex.</strong> Jogador que <em>você draftou</em> e virou 85+ soma no seu teto: 85–89 = +3M · 90–92 = +5M · 93+ = +8M (até 2 jogadores, enquanto ficarem no time).</div>
    <div><strong>Bônus de prêmio.</strong> MVP +5M · DPOY +3M · MVP das Finais +3M · ROY +2M · All-NBA 1º/2º/3º time +3/+2/+1M — entram na folha só na temporada seguinte.</div>
    <div><strong>Trocas (regra dos 120%).</strong> Nenhum lado pode receber mais de 120% do salário que envia. Pick de 1ª rodada conta 5M e de 2ª rodada 2M nos dois lados. Time acima do teto não pode sair da troca com folha maior.</div>
    <div><strong>Vínculo e renovação.</strong> Cada jogador tem anos de vínculo; quando resta 1 ano (ou zero, na entressafra) aparece o botão <em>Renovar</em> aqui e o pedido do agente na caixa de mensagens. Quem não é renovado sai de graça na virada da temporada. O salário nunca é negociado — é sempre o da tabela por OVR.</div>
    <div><strong>Teto, piso e deadline.</strong> Acima do teto na Trade Deadline (dia <?= $dl ?>) ou no início da temporada, a liga trava o calendário até você regularizar — dispensa (o salário sai na hora), troca ou contratação de agente livre (só se couber no espaço). Abaixo do piso na deadline: perde a pick de 2ª rodada do próximo draft.</div>
  </div>
</section>
<?php render_footer(); ?>
