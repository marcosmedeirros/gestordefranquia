<?php
require_once dirname(__DIR__) . '/helpers.php';
$gmId   = League::gmTeam();
$phase  = League::phase();
$tbl    = Cap::leagueTable();
$capM   = Cap::max();
$floor  = Cap::floor();
$avgM   = (int) Database::meta('cap_avg_m', 0);
$dl     = Cap::deadlineDay();
$day    = League::currentDay();
$viewId = (int) ($_GET['team'] ?? ($gmId ?: ($tbl[0]['id'] ?? 0)));
$s      = $viewId ? Cap::summary($viewId) : null;
$team   = $viewId ? League::team($viewId) : null;
$isMine = $gmId && $viewId === $gmId;
$fmtM   = fn($v) => Cap::m((int) $v);

$stTone = ['ok' => 'ok', 'over' => 'bad', 'under' => 'warn'];
$stText = ['ok' => 'Dentro do teto', 'over' => 'Acima do teto', 'under' => 'Abaixo do piso'];

// Trade Deadline em texto curto (só na temporada regular)
$dlChip = '';
if ($phase === 'regular') {
    $left = $dl - $day;
    $dlChip = $left > 0 ? chip('Deadline no dia ' . $dl . ' · ' . ($left === 1 ? 'falta 1 dia' : "faltam $left dias"), $left <= 5 ? 'warn' : 'info', 'alarm')
            : ($left === 0 ? chip('Deadline hoje', 'warn', 'alarm') : chip('Deadline passou (dia ' . $dl . ')', '', 'lock-fill'));
}

render_header('Folha e teto');

$picker = '<form method="get" class="cap-pick"><input type="hidden" name="p" value="cap">'
    . '<label class="sr-only" for="capTeam">Ver a folha de</label>'
    . '<select id="capTeam" name="team" class="input" onchange="this.form.submit()">';
foreach (League::allTeams() as $t) {
    $picker .= '<option value="' . (int) $t['id'] . '"' . ((int) $t['id'] === $viewId ? ' selected' : '') . '>'
        . e(teamFull($t)) . ((int) $t['id'] === $gmId ? ' (seu time)' : '') . '</option>';
}
$picker .= '</select></form>';

page_head('Folha e teto', [
    'eyebrow' => 'Mercado',
    'sub' => 'Quanto o elenco custa, quanto cabe no teto e quanto cada movimento libera.',
    'actions' => $picker,
]);
?>
<div class="stack">
<?php if ($gmId && Cap::inGrace()): ?>
  <?= note('<b>Carência.</b> Seu save é anterior ao teto salarial. Até o fim da temporada ' . Cap::graceSeason()
      . ' a liga não trava o calendário nem pune pelo piso. Use esse tempo para se adequar.', 'ok', 'shield-check') ?>
<?php endif; ?>

<?php if ($s && $team):
    $scale   = max(1, (int) $s['cap_max'], (int) $s['payroll']);
    $payPct  = $s['payroll'] / $scale * 100;
    $capAt   = round($s['cap_max'] / $scale * 100, 1);
    $floorAt = round($s['floor'] / $scale * 100, 1);
    $tone    = $stTone[$s['status']];
    $bigLbl  = $s['status'] === 'over' ? 'Acima do teto' : ($s['status'] === 'under' ? 'Abaixo do piso' : 'Espaço no teto');
    $bigVal  = $s['status'] === 'over' ? $s['excess'] : ($s['status'] === 'under' ? $s['deficit'] : $s['space']);
    $bigCls  = $s['status'] === 'over' ? 'neg' : ($s['status'] === 'under' ? 'warn-txt' : 'pos');
    $heroCls = $s['status'] === 'over' ? 'alert' : ($isMine ? 'hot' : '');
?>
  <section class="panel cap-hero <?= $heroCls ?>">
    <div class="cap-hero-top">
      <div class="cap-id">
        <?= team_logo($team['abbr'], $team['primary_color'] ?? '#333', 'lg') ?>
        <div class="cap-id-txt">
          <span class="eyebrow"><?= $isMine ? 'Sua franquia' : 'Outro time' ?></span>
          <h2><?= e(teamFull($team)) ?></h2>
          <div class="row">
            <?= chip($stText[$s['status']], $tone, $s['status'] === 'ok' ? 'check-circle-fill' : 'exclamation-triangle-fill') ?>
            <?= chip('Elenco ' . $s['count'] . '/' . Cap::ROSTER_MAX, $s['count'] >= Cap::ROSTER_MAX ? 'warn' : '', 'people-fill') ?>
            <?= $dlChip ?>
          </div>
        </div>
      </div>
      <div class="cap-big">
        <span class="label"><?= e($bigLbl) ?></span>
        <b class="big-num <?= $bigCls ?>"><?= $s['status'] === 'over' ? '−' : '' ?><?= $fmtM($bigVal) ?></b>
      </div>
    </div>

    <div>
      <div class="capbar" role="img" aria-label="Folha de <?= e($fmtM($s['payroll'])) ?> para um teto de <?= e($fmtM($s['cap_max'])) ?> e piso de <?= e($fmtM($s['floor'])) ?>">
        <?= meter($payPct, $tone) ?>
        <i class="cb-tick floor" style="--at:<?= $floorAt ?>%"></i>
        <i class="cb-tick cap" style="--at:<?= $capAt ?>%"></i>
      </div>
      <div class="cb-scale">
        <span><i class="cb-key pay <?= $tone ?>"></i>Folha <b><?= $fmtM($s['payroll']) ?></b></span>
        <span><i class="cb-key floor"></i>Piso <b><?= $fmtM($s['floor']) ?></b></span>
        <span><i class="cb-key cap"></i>Teto <b><?= $fmtM($s['cap_max']) ?></b></span>
      </div>
    </div>

    <div class="stats">
      <?= stat_tile('Folha total', $fmtM($s['payroll']), $s['count'] . ' jogadores') ?>
      <?= stat_tile('Teto do time', $fmtM($s['cap_max']), $s['flex_total'] ? 'base ' . $fmtM($s['cap_base']) . ' + Cap Flex ' . $s['flex_total'] . 'M' : 'sem Cap Flex') ?>
      <?= stat_tile('Piso', $fmtM($s['floor']), $s['status'] === 'under' ? 'faltam ' . $fmtM($s['deficit']) : 'folga de ' . $fmtM($s['payroll'] - $s['floor']), $s['status'] === 'under' ? 'warn' : '') ?>
      <?= stat_tile('Elenco', $s['count'] . '/' . Cap::ROSTER_MAX, 'mínimo de ' . Cap::ROSTER_MIN, ($s['count'] < Cap::ROSTER_MIN || $s['count'] > Cap::ROSTER_MAX) ? 'neg' : '') ?>
    </div>

    <?php if ($isMine): ?>
      <div class="stack-sm">
        <?php foreach (Cap::suggestions($s) as $sg):
          $nt = ['danger' => 'bad', 'warn' => 'warn', 'ok' => 'ok', 'info' => 'info', 'tip' => 'info'][$sg['type']] ?? 'info';
          echo note(e($sg['text']), $nt, $sg['type'] === 'tip' ? 'lightbulb-fill' : '');
        endforeach; ?>
      </div>
      <div class="row">
        <?php if (Cap::tradesOpen()): ?>
          <a class="btn btn-primary" href="<?= url('trades') ?>"><?= bi('arrow-left-right') ?>Central de trocas</a>
        <?php else: ?>
          <span class="btn is-off" title="Janela de trocas fechada"><?= bi('lock-fill') ?>Trocas fechadas</span>
        <?php endif; ?>
        <?php if (Cap::signingOpen()): ?><a class="btn" href="<?= url('freeagency') ?>"><?= bi('person-plus-fill') ?>Agentes livres</a><?php endif; ?>
        <a class="btn btn-ghost" href="<?= url('lineup') ?>"><?= bi('people-fill') ?>Escalação</a>
      </div>
    <?php elseif ($gmId): ?>
      <div class="row"><a class="btn btn-ghost" href="<?= url('cap') ?>"><?= bi('arrow-left') ?>Ver a minha folha</a></div>
    <?php endif; ?>
  </section>

  <section class="panel pad-0"<?= $isMine ? ' id="folha"' : '' ?>>
    <?= panel_head($isMine ? 'Folha do elenco' : 'Folha do ' . $team['abbr'], ['icon' => 'cash-stack',
        'meta' => $s['count'] . ' jogadores · maior salário primeiro']) ?>
    <div class="table-wrap">
      <table class="tbl cap-tbl cap-roster">
        <thead><tr>
          <th>Jogador</th><th class="c">OVR</th><th class="num">Salário</th><th class="hide-sm">Peso no teto</th>
          <th class="hide-sm">Origem</th><th class="num hide-sm">Vínculo</th>
          <?php if ($isMine): ?><th class="num"><span class="sr-only">Ações</span></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($s['roster'] as $p):
          $sal   = (int) $p['salary'];
          $yrs   = (int) $p['contract_years'];
          $small = $p['pos'] . ' · ' . (int) $p['age'] . ' anos' . ((int) $p['injury_games'] ? ' · lesionado' : '');
          $src   = $p['source'] === 'rookie'
              ? 'Rookie scale · ' . ((int) $p['draft_round'] === 1 ? 'pick #' . (int) $p['draft_pos'] : '2ª rodada')
              : 'Tabela por OVR';
          $share = $s['cap_max'] > 0 ? $sal / $s['cap_max'] * 100 : 0;
        ?>
          <tr>
            <td><?= player_who($p, $small, $team['primary_color'] ?? '#1a1a2e') ?></td>
            <td class="c"><?= ovr_badge($p['ovr'], 'sm') ?></td>
            <td class="num"><span class="sal"><?= $fmtM($sal) ?></span></td>
            <td class="hide-sm"><div class="cap-share"><?= meter($share) ?><small><?= round($share) ?>%</small></div></td>
            <td class="hide-sm">
              <span class="cap-src"><?= e($src) ?></span>
              <?php if ($p['flex_counted'] || $p['flex'] > 0 || $p['source'] === 'rookie' || $p['bonus_m']): ?>
              <div class="cap-flags">
                <?php if ($p['flex_counted']): ?><span class="chip info" title="Draftado por este time: soma <?= (int) $p['flex'] ?>M no teto"><?= bi('arrow-up-circle-fill') ?>Cap Flex +<?= (int) $p['flex'] ?>M</span>
                <?php elseif ($p['flex'] > 0): ?><span class="chip" title="Elegível ao Cap Flex, mas só <?= Cap::FLEX_MAX_PLAYERS ?> jogadores contam">Flex elegível</span><?php endif; ?>
                <?php if ($p['source'] === 'rookie'): ?><?= chip('Calouro', 'go') ?><?php endif; ?>
                <?php if ($p['bonus_m']): ?><?= chip('Bônus de prêmio +' . (int) $p['bonus_m'] . 'M', 'warn', 'award-fill') ?><?php endif; ?>
              </div>
              <?php endif; ?>
            </td>
            <td class="num hide-sm"><?= $yrs > 0 ? $yrs . ' ano' . ($yrs > 1 ? 's' : '') : chip('Expira', 'warn') ?></td>
            <?php if ($isMine):
              // o que a dispensa libera: salário inteiro, menos o mínimo que a liga põe se o elenco estiver no limite
              $fill  = max(0, Cap::ROSTER_MIN - ($s['count'] - 1)) * Cap::VETERAN_MIN * Cap::M;
              $after = $s['payroll'] - $sal + $fill;
              $relTxt = 'Dispensar ' . $p['name'] . '? Ele vira agente livre e o salário de ' . $fmtM($sal) . ' sai da folha na hora.';
              if ($fill) $relTxt .= ' Como o elenco está no mínimo, a liga completa a vaga com um jogador de ' . $fmtM($fill) . '.';
              $relTxt .= ' A folha vai de ' . $fmtM($s['payroll']) . ' para ' . $fmtM($after) . '.';
              if ($p['flex_counted']) {
                  $relTxt .= ' O Cap Flex de +' . (int) $p['flex'] . 'M dele sai do teto.';
              } else {
                  $relTxt .= $after > $s['cap_max'] ? ' Ainda ficaria ' . $fmtM($after - $s['cap_max']) . ' acima do teto.' : ' Sobraria ' . $fmtM($s['cap_max'] - $after) . ' de espaço.';
              }
            ?>
            <td class="num">
              <div class="cap-acts">
                <?php if ($yrs <= 1):
                  $dem = League::resignDemand($p);
                  $diff = (int) $dem['salary'] - $sal;
                  $renTxt = 'Renovar ' . $p['name'] . ' por ' . $dem['years'] . ' anos? O salário segue a tabela por OVR: ' . $fmtM($dem['salary']) . '/ano (hoje ' . $fmtM($sal) . ').'
                      . ($diff !== 0 ? ' A folha ' . ($diff > 0 ? 'sobe ' : 'cai ') . $fmtM(abs($diff)) . '.' : ' A folha não muda.');
                ?>
                  <a class="btn btn-sm btn-ok cap-act" href="<?= url('home', ['action' => 'resign', 'pid' => $p['id'], 'choice' => 'accept', 'back' => 'cap']) ?>"
                     title="Renovar por <?= (int) $dem['years'] ?> anos a <?= e($fmtM($dem['salary'])) ?>/ano" aria-label="Renovar <?= e($p['name']) ?>"
                     <?= link_attrs(['confirm' => ['text' => $renTxt, 'title' => 'Renovar contrato', 'ok' => 'Renovar']]) ?>><?= bi('pen-fill') ?><span class="hide-sm">Renovar <?= (int) $dem['years'] ?> anos</span></a>
                <?php endif; ?>
                <a class="btn btn-sm btn-danger cap-act" href="<?= url('home', ['action' => 'release', 'pid' => $p['id'], 'back' => 'cap']) ?>"
                   title="Libera <?= e($fmtM($sal - $fill)) ?> da folha" aria-label="Dispensar <?= e($p['name']) ?>"
                   <?= link_attrs(['confirm' => ['text' => $relTxt, 'title' => 'Dispensar jogador', 'ok' => 'Dispensar', 'danger' => true]]) ?>><?= bi('box-arrow-right') ?><span class="hide-sm">Dispensar</span></a>
              </div>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

  <section class="panel pad-0">
    <?= panel_head('Folha da liga', ['icon' => 'bar-chart-fill',
        'meta' => 'Temporada ' . League::season() . ' · teto ' . $fmtM($capM) . ' + Cap Flex · piso ' . $fmtM($floor)]) ?>
    <div class="table-wrap">
      <table class="tbl compact cap-tbl cap-league">
        <thead><tr>
          <th class="c hide-sm">#</th><th>Time</th><th class="num">Folha</th><th class="hide-sm">Uso do teto</th>
          <th class="num hide-sm">Teto</th><th class="num">Espaço</th><th class="num hide-sm">Elenco</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php $i = 1; foreach ($tbl as $t):
          $use = $t['cap_max'] > 0 ? $t['payroll'] / $t['cap_max'] * 100 : 0; ?>
          <tr class="<?= (int) $t['id'] === $gmId ? 'mine' : '' ?>">
            <td class="rank hide-sm"><?= $i++ ?></td>
            <td><a class="who" href="<?= url('cap', ['team' => $t['id']]) ?>"<?= (int) $t['id'] === $viewId ? ' aria-current="true"' : '' ?>><?= team_logo($t['abbr'], $t['color'] ?? '#333', 'sm') ?>
              <span class="w-txt"><b><?= e($t['abbr']) ?><span class="hide-sm"> · <?= e($t['city'] . ' ' . $t['name']) ?></span></b></span></a></td>
            <td class="num"><span class="sal"><?= $fmtM($t['payroll']) ?></span></td>
            <td class="hide-sm"><div class="cap-share"><?= meter($use, $stTone[$t['status']]) ?><small><?= round($use) ?>%</small></div></td>
            <td class="num hide-sm"><?= $fmtM($t['cap_max']) ?><?= $t['flex'] ? ' <span class="dim">+' . (int) $t['flex'] . ' flex</span>' : '' ?></td>
            <td class="num"><?= $t['space'] < 0 ? '<span class="neg">−' . $fmtM(abs($t['space'])) . '</span>' : $fmtM($t['space']) ?></td>
            <td class="num hide-sm"><?= (int) $t['count'] ?></td>
            <td>
              <?php if ($t['status'] === 'over'): ?><span class="chip bad">Acima<span class="hide-sm"> do teto</span></span>
              <?php elseif ($t['status'] === 'under'): ?><span class="chip warn"><span class="hide-sm">Abaixo do piso</span><span class="only-sm">Piso</span></span>
              <?php else: ?><span class="chip ok">OK</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel">
    <?= panel_head('Como funciona', ['icon' => 'journal-text', 'meta' => 'Regras da FBA ELITE'
        . ($avgM ? ' · folha média na instalação: ' . $avgM . 'M' : '')]) ?>
    <div class="cap-rules">
      <div class="cap-rule"><?= bi('graph-up-arrow') ?><div><b>Salário por OVR</b>
        <p>99→60M · 98→56 · 97→52 · 96→48 · 95→44 · 94→40 · 93→36 · 92→32 · 91→29 · 90→26 · 89→23 · 88→20 · 87→18 · 86→16 · 85→14 · 84→12 · 83→10 · 82→8 · 81→6 · 80→5 · 79→4 · 78→3 · até 77→2M (mínimo de veterano). Quando o OVR muda na entressafra, o salário muda junto.</p></div></div>
      <div class="cap-rule"><?= bi('mortarboard-fill') ?><div><b>Rookie scale no 1º ano</b>
        <p>1ª rodada: picks 1–3 = 18M · 4–8 = 14M · 9–12 = 12M · 13–16 = 8M · 17–22 = 5M · 23+ = 3M. 2ª rodada: 2M. No 2º ano o calouro passa para a tabela por OVR.</p></div></div>
      <div class="cap-rule"><?= bi('arrow-up-circle-fill') ?><div><b>Cap Flex</b>
        <p>Jogador que você draftou e virou 85+ soma no seu teto: 85–89 = +3M · 90–92 = +5M · 93+ = +8M. Vale para até <?= Cap::FLEX_MAX_PLAYERS ?> jogadores, enquanto ficarem no time.</p></div></div>
      <div class="cap-rule"><?= bi('award-fill') ?><div><b>Bônus de prêmio</b>
        <p>MVP +5M · DPOY +3M · MVP das Finais +3M · ROY +2M · All-NBA 1º/2º/3º time +3/+2/+1M. Entra na folha só na temporada seguinte.</p></div></div>
      <div class="cap-rule"><?= bi('arrow-left-right') ?><div><b>Trocas: regra dos 120%</b>
        <p>Nenhum lado recebe mais de 120% do salário que envia. Pick de 1ª rodada conta 5M e de 2ª conta 2M nos dois lados. Time acima do teto não pode sair da troca com folha maior.</p></div></div>
      <div class="cap-rule"><?= bi('pen-fill') ?><div><b>Vínculo e renovação</b>
        <p>Quando resta 1 ano (ou zero, na entressafra), aparece o botão Renovar aqui e o pedido do agente na caixa de entrada. Quem não renova sai de graça na virada da temporada. O salário é sempre o da tabela por OVR.</p></div></div>
      <div class="cap-rule"><?= bi('alarm-fill') ?><div><b>Teto, piso e deadline</b>
        <p>Acima do teto na Trade Deadline (dia <?= $dl ?>) ou no início da temporada, a liga trava o calendário até você regularizar: dispensa, troca ou agente livre que caiba no espaço. Abaixo do piso na deadline, o time perde a pick de 2ª rodada do próximo draft.</p></div></div>
    </div>
  </section>
</div>
<?php render_footer(); ?>
