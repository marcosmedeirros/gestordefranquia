<?php
require_once dirname(__DIR__) . '/helpers.php';
$gmId = League::gmTeam();
render_header('Trocas');
if (!$gmId) {
    page_head('Trocas', ['eyebrow' => 'Mercado', 'sub' => 'Negocie jogadores e picks com os outros times.']);
    echo note('Escolha uma franquia primeiro em <a href="' . url('gmselect') . '">Franquias</a>.', 'info');
    render_footer(); exit;
}
$gm      = League::team($gmId);
$others  = array_values(array_filter(League::allTeams(), fn($t) => (int)$t['id'] !== $gmId));
$aiId    = (int)($_GET['ai'] ?? $others[0]['id']);
$ai      = League::team($aiId);
$myRoster= League::roster($gmId);
$aiRoster= League::roster($aiId);
usort($myRoster, fn($a,$b) => $b['ovr'] <=> $a['ovr']);
usort($aiRoster, fn($a,$b) => $b['ovr'] <=> $a['ovr']);
$myPicks = League::teamPicks($gmId);
$aiPicks = League::teamPicks($aiId);

// Contraproposta recebida
$counter = [];
if (!empty($_GET['counter'])) {
    $counter = array_filter(array_map('intval', explode(',', $_GET['counter'])));
}
$counterGive = !empty($_GET['cgive']) ? array_filter(array_map('intval', explode(',', $_GET['cgive']))) : [];
$open    = Cap::tradesOpen();
$gmCap   = Cap::summary($gmId);
$aiCap   = Cap::summary($aiId);
$dl      = Cap::deadlineDay();
$block   = League::tradeBlock();
$aiTop   = array_map(fn($p) => (int) $p['id'], array_slice($aiRoster, 0, 3)); // a IA cobra mais por estes

$capChip = function (array $s): string {
    return $s['status'] === 'over' ? chip(Cap::m((int) $s['excess']) . ' acima do teto', 'bad')
        : ($s['status'] === 'under' ? chip(Cap::m((int) $s['deficit']) . ' abaixo do piso', 'warn') : chip(Cap::m((int) $s['space']) . ' de espaço', 'ok'));
};

/** Um jogador ou pick selecionável no montador da troca. */
function trade_item(array $p, string $field, string $color, array $flags = []): string
{
    $sal = (int) ($p['salary'] ?? 0);
    return '<label class="tp" data-val="' . tradeValue($p) . '" data-sal="' . $sal . '" data-name="' . e((string) $p['name']) . '">'
        . '<input class="tp-cb" type="checkbox" name="' . $field . '" value="' . (int) $p['id'] . '">'
        . player_photo((int) ($p['nba_id'] ?? 0), (string) $p['name'], $color, 'sm', 'face', (int) $p['id'], (string) $p['pos'])
        . '<span class="tp-txt"><b>' . e((string) $p['name']) . '</b><small>' . e($p['pos'] . ' · ' . (int) $p['age'] . ' anos · ' . Cap::m($sal)) . '</small>'
        . ($flags ? '<span class="tp-flags">' . implode('', $flags) . '</span>' : '') . '</span>'
        . ovr_badge($p['ovr'], 'sm')
        . '<span class="tp-mark" aria-hidden="true">' . bi('check-lg') . '</span></label>';
}

function trade_pick(array $pk, string $field): string
{
    $via = ((int) $pk['original_team_id'] !== (int) $pk['owner_team_id']) ? 'via ' . $pk['orig_abbr'] : 'própria';
    $sal = (Cap::PICK_VALUE[(int) $pk['round']] ?? 0) * Cap::M;
    $label = 'R' . (int) $pk['round'] . ' · ' . League::draftYearLabel((int) $pk['year']);
    return '<label class="tp pick" data-val="' . ((int) $pk['round'] === 1 ? 12 : 4) . '" data-sal="' . $sal . '" data-name="pick ' . e($label) . '">'
        . '<input class="tp-cb" type="checkbox" name="' . $field . '" value="' . (int) $pk['id'] . '">'
        . '<span class="tp-pk">R' . (int) $pk['round'] . '</span>'
        . '<span class="tp-txt"><b>Pick ' . e($label) . '</b><small>' . e($via) . ' · conta ' . Cap::m($sal) . '</small></span>'
        . '<span class="tp-mark" aria-hidden="true">' . bi('check-lg') . '</span></label>';
}

page_head('Trocas', [
    'eyebrow' => 'Mercado',
    'sub' => 'Monte o pacote dos dois lados, confira o casamento salarial e proponha. A IA responde na hora.',
    'actions' => '<button class="btn btn-primary" type="submit" form="tradeForm" data-propose' . ($open ? '' : ' disabled') . '>'
        . bi('arrow-left-right') . 'Propor troca</button>',
]);
?>
<div class="stack">
<?php if (!$open): ?>
  <?= note('<b>Janela de trocas fechada.</b> ' . (League::phase() === 'regular'
      ? 'A Trade Deadline (dia ' . $dl . ') já passou. As trocas voltam na entressafra.'
      : 'Trocas só na pré-temporada, na free agency e na temporada regular até a deadline.'), 'bad', 'lock-fill') ?>
<?php endif; ?>

<?php if ($counter):
  $cGive = array_values(array_filter($myRoster, fn($p) => in_array((int) $p['id'], $counterGive)));
  $cGet  = array_values(array_filter($aiRoster, fn($p) => in_array((int) $p['id'], $counter)));
  $cGiveSal = array_sum(array_map(fn($p) => (int) $p['salary'], $cGive));
  $cGetSal  = array_sum(array_map(fn($p) => (int) $p['salary'], $cGet));
  $names = fn(array $ps) => implode(', ', array_column($ps, 'name')) ?: 'nada';
?>
  <section class="panel hot tr-counter">
    <?= panel_head('Contraproposta do ' . $ai['abbr'], ['icon' => 'chat-left-quote-fill', 'right' => chip('Esperando você', 'go', 'hourglass-split')]) ?>
    <p class="muted"><?= e(teamFull($ai)) ?> topa negociar se o pacote ficar assim:</p>
    <div class="tr-sides">
      <div>
        <div class="sub-h">Você envia · <?= Cap::m($cGiveSal) ?></div>
        <div class="stack-sm">
          <?php foreach ($cGive as $p): ?>
            <div class="spread tr-mini"><?= player_who($p, $p['pos'] . ' · ' . Cap::m((int) $p['salary']), $gm['primary_color'] ?? '#1a1a2e') ?><?= ovr_badge($p['ovr'], 'sm') ?></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="tr-swap" aria-hidden="true"><?= bi('arrow-left-right') ?></div>
      <div>
        <div class="sub-h">Você recebe · <?= Cap::m($cGetSal) ?></div>
        <div class="stack-sm">
          <?php foreach ($cGet as $p): ?>
            <div class="spread tr-mini"><?= player_who($p, $p['pos'] . ' · ' . Cap::m((int) $p['salary']), $ai['primary_color'] ?? '#1a1a2e') ?><?= ovr_badge($p['ovr'], 'sm') ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="tr-acts">
      <form method="post" action="<?= url('home', ['action'=>'propose-trade']) ?>">
        <input type="hidden" name="ai_team" value="<?= $aiId ?>">
        <?php foreach ($counterGive as $cid): ?>
          <input type="hidden" name="give[]" value="<?= $cid ?>">
        <?php endforeach; ?>
        <?php foreach ($counter as $cid): ?>
          <input type="hidden" name="get[]" value="<?= $cid ?>">
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit"<?= $open ? '' : ' disabled' ?>
          <?= link_attrs(['confirm' => ['text' => 'Enviar ' . $names($cGive) . ' e receber ' . $names($cGet) . '? Se o ' . $ai['abbr'] . ' confirmar, a troca é feita na hora.', 'title' => 'Aceitar contraproposta', 'ok' => 'Aceitar']]) ?>><?= bi('check-lg') ?>Aceitar contraproposta</button>
      </form>
      <a class="btn btn-ghost" href="<?= url('trades',['ai'=>$aiId]) ?>"><?= bi('x-lg') ?>Recusar</a>
    </div>
  </section>
<?php endif; ?>

  <section class="panel tr-partner">
    <form method="get" class="field tr-pick">
      <input type="hidden" name="p" value="trades">
      <label for="aiSel">Negociar com</label>
      <select id="aiSel" name="ai" class="input" onchange="this.form.submit()">
        <?php foreach ($others as $o): ?>
          <option value="<?= $o['id'] ?>" <?= $o['id']==$aiId?'selected':'' ?>>
            <?= e($o['city'].' '.$o['name']) ?> · Folha <?= money(League::teamPayroll((int)$o['id'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <div class="tr-partner-info">
      <?= team_who($ai, ($ai['conf'] === 'E' ? 'Leste' : 'Oeste') . ' · ' . (int) $ai['wins'] . '-' . (int) $ai['losses']) ?>
      <div class="row"><?= chip('Folha ' . Cap::m((int) $aiCap['payroll']) . ' / ' . Cap::m((int) $aiCap['cap_max']), '', 'cash-stack') ?><?= $capChip($aiCap) ?></div>
    </div>
  </section>

  <form method="post" action="<?= url('home', ['action'=>'propose-trade']) ?>" id="tradeForm">
    <input type="hidden" name="ai_team" value="<?= $aiId ?>">
    <div class="tr-builder">
      <section class="panel tr-side" id="myPlayers">
        <?= panel_head('Você envia', ['icon' => 'box-arrow-up-right', 'right' => '<span class="chip" id="mySel">Nada selecionado</span>']) ?>
        <div class="spread tr-team"><?= team_who($gm, 'Folha ' . Cap::m((int) $gmCap['payroll']) . ' · teto ' . Cap::m((int) $gmCap['cap_max']), false) ?><?= $capChip($gmCap) ?></div>
        <div class="tr-list">
          <?php foreach ($myRoster as $p) {
              $onBlock = in_array((int) $p['id'], $block, true);
              echo trade_item($p, 'give[]', $gm['primary_color'] ?? '#1a1a2e', $onBlock ? [chip('Vitrine', 'go', 'megaphone-fill')] : []);
          } ?>
        </div>
        <?php if ($block): ?>
          <p class="tr-hint"><?= bi('megaphone-fill') ?>Quem está na vitrine recebe propostas na caixa de entrada. <a class="link" href="<?= url('lineup') ?>">Gerenciar na escalação</a></p>
        <?php endif; ?>
        <?php if ($myPicks): ?>
          <div class="sub-h">Picks</div>
          <div class="tr-list"><?php foreach ($myPicks as $pk) echo trade_pick($pk, 'give_pick[]'); ?></div>
        <?php endif; ?>
      </section>

      <section class="panel tr-side" id="aiPlayers">
        <?= panel_head('Você recebe', ['icon' => 'box-arrow-in-down-left', 'right' => '<span class="chip" id="aiSel2">Nada selecionado</span>']) ?>
        <div class="spread tr-team"><?= team_who($ai, 'Folha ' . Cap::m((int) $aiCap['payroll']) . ' · teto ' . Cap::m((int) $aiCap['cap_max']), false) ?><?= $capChip($aiCap) ?></div>
        <div class="tr-list">
          <?php foreach ($aiRoster as $p) {
              $top = in_array((int) $p['id'], $aiTop, true);
              echo trade_item($p, 'get[]', $ai['primary_color'] ?? '#1a1a2e', $top ? ['<span class="chip info" title="A IA cobra mais pelos 3 melhores dela">' . bi('star-fill') . 'Top 3</span>'] : []);
          } ?>
        </div>
        <?php if ($aiPicks): ?>
          <div class="sub-h">Picks</div>
          <div class="tr-list"><?php foreach ($aiPicks as $pk) echo trade_pick($pk, 'get_pick[]'); ?></div>
        <?php endif; ?>
      </section>

      <aside class="tr-sum">
        <section class="panel" id="tradeSummary">
          <?= panel_head('Análise da troca', ['icon' => 'clipboard-data-fill']) ?>
          <div class="tr-val">
            <div class="tr-val-row"><span><?= e($gm['abbr']) ?> envia</span><div class="tr-bar" id="myValBar"><?= meter(0) ?></div><b id="myValNum">0</b></div>
            <div class="tr-val-row"><span><?= e($ai['abbr']) ?> envia</span><div class="tr-bar them" id="aiValBar"><?= meter(0) ?></div><b id="aiValNum">0</b></div>
          </div>
          <div class="note info" id="tradeVerdict"><i class="bi bi-info-circle-fill" aria-hidden="true"></i><div>Selecione jogadores ou picks para ver a análise.</div></div>

          <div class="sub-h">Salários · regra dos 120%</div>
          <div class="tr-sal" id="tradeSalary"
               data-gm-pay="<?= $gmCap['payroll'] ?>" data-gm-cap="<?= $gmCap['cap_max'] ?>" data-gm-count="<?= $gmCap['count'] ?>"
               data-ai-pay="<?= $aiCap['payroll'] ?>" data-ai-cap="<?= $aiCap['cap_max'] ?>" data-ai-count="<?= $aiCap['count'] ?>"
               data-gm-abbr="<?= e($gm['abbr']) ?>" data-ai-abbr="<?= e($ai['abbr']) ?>"
               data-min="<?= Cap::ROSTER_MIN ?>" data-max="<?= Cap::ROSTER_MAX ?>">
            <?php foreach (['Gm' => $gm['abbr'], 'Ai' => $ai['abbr']] as $k => $abbr): ?>
            <div class="tr-sal-side">
              <div class="spread"><b class="tr-abbr"><?= e($abbr) ?></b><span class="chip" id="ts<?= $k ?>Chip">—</span></div>
              <div class="tr-sal-nums"><span>Envia<b id="ts<?= $k ?>Send">$0.0M</b></span><span>Recebe<b id="ts<?= $k ?>Recv">$0.0M</b></span></div>
              <div class="tr-bar" id="ts<?= $k ?>Bar"><?= meter(0) ?></div>
              <small id="ts<?= $k ?>Limit">Pode receber até $0.0M</small>
              <small id="ts<?= $k ?>After">Folha depois: —</small>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="note info" id="tsVerdict"><i class="bi bi-info-circle-fill" aria-hidden="true"></i><div>Picks contam $5.0M (1ª rodada) e $2.0M (2ª) nos dois lados.</div></div>

          <button class="btn btn-primary btn-lg btn-block" type="submit" data-propose<?= $open ? '' : ' disabled' ?>><?= bi('arrow-left-right') ?>Propor troca</button>
          <p class="dim tr-foot">A IA avalia valor, idade, potencial e necessidade de posição, e cobra mais pelos 3 melhores dela. Se recusar, pode mandar uma contraproposta.</p>
        </section>
      </aside>
    </div>
  </form>
</div>

<script>
(function(){
  const form = document.getElementById('tradeForm');
  const box = document.getElementById('tradeSalary');
  if (!form || !box) return;
  const d = box.dataset;
  const $ = id => document.getElementById(id);
  const M = v => '$' + (v / 1e6).toFixed(1) + 'M';
  const picked = side => Array.from(document.querySelectorAll('#' + side + ' .tp-cb:checked')).map(cb => cb.closest('.tp'));
  const sum = (items, key, onlyPlayers) => items.reduce((t, c) => (onlyPlayers && c.classList.contains('pick')) ? t : t + parseFloat(c.dataset[key] || 0), 0);
  const bar = (id, pct, tone) => {
    const m = document.querySelector('#' + id + ' .meter');
    m.className = 'meter' + (tone ? ' ' + tone : '');
    m.firstElementChild.style.width = Math.max(0, Math.min(100, pct)) + '%';
  };
  const noteSet = (el, tone, icon, text) => {
    el.className = 'note ' + tone;
    el.querySelector('i').className = 'bi bi-' + icon;
    el.querySelector('div').textContent = text;
  };
  const chipSet = (el, tone, text) => { el.className = 'chip' + (tone ? ' ' + tone : ''); el.textContent = text; };

  function update() {
    const mine = picked('myPlayers'), theirs = picked('aiPlayers');
    document.querySelectorAll('.tp').forEach(c => c.classList.toggle('is-on', c.querySelector('.tp-cb').checked));
    const selTxt = items => items.length ? (items.length === 1 ? '1 item' : items.length + ' itens') + ' · ' + M(sum(items, 'sal', false)) : 'Nada selecionado';
    chipSet($('mySel'), mine.length ? 'team' : '', selTxt(mine));
    chipSet($('aiSel2'), theirs.length ? 'team' : '', selTxt(theirs));

    // valor esportivo, pela ótica da IA: ela recebe o que você envia (mv) e entrega o que você pede (av)
    const mv = sum(mine, 'val'), av = sum(theirs, 'val');
    const max = Math.max(mv, av, 1);
    bar('myValBar', mv / max * 100); bar('aiValBar', av / max * 100);
    $('myValNum').textContent = Math.round(mv); $('aiValNum').textContent = Math.round(av);
    const v = $('tradeVerdict');
    if (mv === 0 && av === 0) noteSet(v, 'info', 'info-circle-fill', 'Selecione jogadores ou picks para ver a análise.');
    else {
      const diff = av > 0 ? (mv - av) / av : 1;
      if (diff >= 0.12) noteSet(v, 'ok', 'check-circle-fill', 'A IA sai ganhando: aprovação provável.');
      else if (diff >= -0.06) noteSet(v, 'ok', 'hand-thumbs-up-fill', 'Troca equilibrada: pode passar (no difícil a IA quer vantagem).');
      else if (diff < -0.30) noteSet(v, 'bad', 'x-octagon-fill', 'Você pede muito mais do que oferece: recusa quase certa.');
      else noteSet(v, 'warn', 'exclamation-triangle-fill', 'Você leva vantagem: provável contraproposta.');
    }

    // casamento salarial (regra dos 120%), tetos e tamanho dos elencos
    const gmSend = sum(mine, 'sal', false), aiSend = sum(theirs, 'sal', false);
    const gmSal = sum(mine, 'sal', true), aiSal = sum(theirs, 'sal', true);
    const gmPay = +d.gmPay, gmCap = +d.gmCap, aiPay = +d.aiPay, aiCap = +d.aiCap;
    const gmLim = Math.floor(gmSend * 1.2), aiLim = Math.floor(aiSend * 1.2);
    const gmAfter = gmPay - gmSal + aiSal, aiAfter = aiPay - aiSal + gmSal;
    const nMine = mine.filter(c => !c.classList.contains('pick')).length, nTheirs = theirs.filter(c => !c.classList.contains('pick')).length;
    const gmCount = +d.gmCount - nMine + nTheirs, aiCount = +d.aiCount - nTheirs + nMine;
    const empty = gmSend === 0 && aiSend === 0;
    const side = (k, send, recv, lim, after, pay, cap) => {
      $('ts' + k + 'Send').textContent = M(send);
      $('ts' + k + 'Recv').textContent = M(recv);
      $('ts' + k + 'Limit').textContent = 'Pode receber até ' + M(lim);
      const overCap = after > cap && after > pay, overLim = recv > lim;
      const a = $('ts' + k + 'After');
      a.textContent = 'Folha depois: ' + M(after) + ' / teto ' + M(cap);
      a.className = overCap ? 'neg' : '';
      bar('ts' + k + 'Bar', lim > 0 ? recv / lim * 100 : (recv > 0 ? 100 : 0), empty ? '' : (overLim ? 'bad' : 'ok'));
      if (empty) chipSet($('ts' + k + 'Chip'), '', '—');
      else if (overLim) chipSet($('ts' + k + 'Chip'), 'bad', 'Recebe demais');
      else if (overCap) chipSet($('ts' + k + 'Chip'), 'bad', 'Estoura o teto');
      else chipSet($('ts' + k + 'Chip'), 'ok', 'Dentro');
    };
    side('Gm', gmSend, aiSend, gmLim, gmAfter, gmPay, gmCap);
    side('Ai', aiSend, gmSend, aiLim, aiAfter, aiPay, aiCap);
    const t = $('tsVerdict');
    if (empty) noteSet(t, 'info', 'info-circle-fill', 'Picks contam $5.0M (1ª rodada) e $2.0M (2ª) nos dois lados.');
    else {
      const probs = [];
      if (aiSend > gmLim) probs.push(d.gmAbbr + ' recebe ' + M(aiSend) + ' mas só pode receber ' + M(gmLim) + '. Envie pelo menos ' + M(Math.ceil(aiSend / 1.2)));
      if (gmSend > aiLim) probs.push(d.aiAbbr + ' recebe ' + M(gmSend) + ' mas só pode receber ' + M(aiLim));
      if (gmAfter > gmCap && gmAfter > gmPay) probs.push('sua folha sairia acima do teto');
      if (aiAfter > aiCap && aiAfter > aiPay) probs.push('a folha do ' + d.aiAbbr + ' sairia acima do teto');
      if (gmCount < +d.min || gmCount > +d.max) probs.push(d.gmAbbr + ' ficaria com ' + gmCount + ' jogadores (' + d.min + ' a ' + d.max + ')');
      if (aiCount < +d.min || aiCount > +d.max) probs.push(d.aiAbbr + ' ficaria com ' + aiCount + ' jogadores (' + d.min + ' a ' + d.max + ')');
      if (probs.length) noteSet(t, 'bad', 'x-octagon-fill', probs.join(' · ') + '.');
      else noteSet(t, 'ok', 'check-circle-fill', 'Salários casam dentro dos 120% e dos tetos.');
    }

    // confirmação com o pacote na mão; sem nada marcado, o servidor explica
    const names = items => items.map(c => c.dataset.name).join(', ') || 'nada';
    document.querySelectorAll('[data-propose]').forEach(b => {
      if (empty && !mine.length && !theirs.length) { b.removeAttribute('data-confirm'); return; }
      b.dataset.confirm = 'Enviar ' + names(mine) + ' e receber ' + names(theirs) + '? Se o ' + d.aiAbbr + ' aceitar, a troca é feita na hora.';
      b.dataset.confirmTitle = 'Propor troca';
      b.dataset.confirmOk = 'Propor';
    });
  }
  form.addEventListener('change', update);
  update();
})();
</script>

<?php render_footer(); ?>

<?php
function tradeValue(array $p): float {
    $ovr = (int)$p['ovr'];
    $age = (int)$p['age'];
    $pot = (int)($p['potential'] ?? $ovr);
    // Base: OVR com bônus de potencial e penalidade de idade
    $base = $ovr + max(0, ($pot - $ovr) * 0.4);
    $agePenalty = max(0, ($age - 27) * 0.8);
    return round($base - $agePenalty, 1);
}
?>
