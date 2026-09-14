<?php
require_once dirname(__DIR__) . '/helpers.php';

$gmId = (int) League::gmTeam();
render_header('Escalação');
if (!$gmId) {
    page_head('Escalação', ['eyebrow' => 'Elenco']);
    echo note('Você ainda não comanda um time. <a href="' . url('gmselect') . '">Escolher uma franquia</a>', 'info');
    render_footer();
    exit;
}

$t      = League::team($gmId);
$color  = (string) ($t['primary_color'] ?? '#333');
$roster = League::roster($gmId);
$block  = League::tradeBlock();
$next   = League::upcomingGames($gmId, 1)[0] ?? null;

/* Titulares (na ordem das posições), reservas (mais minutos primeiro) e
   lesionados. Lesionado fica fora do formulário: o campo que não vai no POST
   sai da rotação, como o campo desabilitado fazia antes. */
$posRank  = ['PG' => 1, 'SG' => 2, 'SF' => 3, 'PF' => 4, 'C' => 5];
$starters = $bench = $injured = [];
foreach ($roster as $p) {
    if ((int) ($p['injury_games'] ?? 0) > 0)        $injured[]  = $p;
    elseif ((int) ($p['is_starter'] ?? 0) === 1)    $starters[] = $p;
    else                                            $bench[]    = $p;
}
usort($starters, fn($a, $b) => [$posRank[$a['pos']] ?? 9, -(int) $a['ovr']] <=> [$posRank[$b['pos']] ?? 9, -(int) $b['ovr']]);
usort($bench, fn($a, $b) => [-(int) $a['min_target'], -(int) $a['ovr']] <=> [-(int) $b['min_target'], -(int) $b['ovr']]);
usort($injured, fn($a, $b) => (int) $b['ovr'] <=> (int) $a['ovr']);

/* Como o motor escala (SimEngine): entram os 8 primeiros disponíveis
   (titulares antes, depois o maior OVR). Se a soma dos minutos for zero, ele
   usa a distribuição padrão (MIN_DIST) nesses 8; senão reescala os minutos
   para somar 240. A tela mostra isso e o script abaixo refaz a mesma conta. */
$healthy = array_merge($starters, $bench);
$avail   = array_values(array_filter($healthy, fn($p) => (int) ($p['rest_games'] ?? 0) === 0));
usort($avail, fn($a, $b) => [(int) $b['is_starter'], (int) $b['ovr'], (int) $a['id']] <=> [(int) $a['is_starter'], (int) $a['ovr'], (int) $b['id']]);
$top8  = array_slice($avail, 0, 8);
$plays = array_flip(array_map(fn($p) => (int) $p['id'], $top8));

$autoDist = [];
try {
    $autoDist = array_values(array_map('intval', (array) (new ReflectionClassConstant('SimEngine', 'MIN_DIST'))->getValue()));
} catch (Throwable $e) {
    $autoDist = [];
}

$nStart   = count($starters);
$inRot    = count(array_filter($healthy, fn($p) => (int) $p['min_target'] > 0));
$totalMin = array_sum(array_map(fn($p) => max(0, min(48, (int) $p['min_target'])), $healthy));
$auto     = $totalMin === 0;
$autoMin  = [];
foreach ($top8 as $i => $p) $autoMin[(int) $p['id']] = (int) ($autoDist[$i] ?? 0);
// minutos que o jogador vai ter de fato (automáticos, ou os dele se estiver entre os 8)
$shown   = fn(array $p): int => $auto ? ($autoMin[(int) $p['id']] ?? 0)
                                       : (isset($plays[(int) $p['id']]) ? max(0, min(48, (int) $p['min_target'])) : 0);
$playing = $auto ? count($top8) : count(array_filter($top8, fn($p) => (int) $p['min_target'] > 0));

$issues = [];
if ($nStart !== 5) $issues[] = 'Marque exatamente 5 titulares (agora: ' . $nStart . ').';
if (!$auto && $inRot < 5) {
    $issues[] = 'Dê minutos a pelo menos 5 jogadores, ou volte ao automático.';
} elseif (!$auto && $inRot > 8) {
    $issues[] = 'Só 8 entram em quadra: ' . ($inRot - 8 === 1 ? '1 jogador com minutos vai ficar fora.' : ($inRot - 8) . ' jogadores com minutos vão ficar fora.');
}

$games = fn(int $n): string => $n . ($n === 1 ? ' jogo' : ' jogos');

$flags = function (array $p, bool $withMorale = true) use ($block, $games): string {
    $h    = '';
    $inj  = (int) ($p['injury_games'] ?? 0);
    $rest = (int) ($p['rest_games'] ?? 0);
    $mor  = (int) ($p['morale'] ?? 75);
    if ($inj)      $h .= chip('Fora ' . $games($inj), 'bad', 'bandaid-fill');
    elseif ($rest) $h .= chip('Descanso · ' . $games($rest), 'info', 'moon-stars-fill');
    if ($withMorale && $mor < 65) $h .= chip('Moral ' . $mor, 'warn', 'emoji-frown');
    if (!empty($p['dev_focus'])) $h .= chip('Foco de treino', 'info', 'bullseye');
    if (in_array((int) $p['id'], $block, true)) $h .= chip('Na vitrine', 'warn', 'megaphone-fill');
    return $h;
};

// Carta de jogador com os controles da rotação (titular + minutos).
$card = function (array $p) use ($color, $flags, $plays, $auto, $autoMin, $shown): string {
    $id    = (int) $p['id'];
    $name  = (string) $p['name'];
    $min   = max(0, min(48, (int) ($p['min_target'] ?? 0)));
    $eff   = $shown($p);
    $st    = (int) ($p['is_starter'] ?? 0) === 1;
    $rest  = (int) ($p['rest_games'] ?? 0);
    $gp    = (int) ($p['gp'] ?? 0);
    $line  = '<span class="pos-tag">' . e((string) $p['pos']) . '</span> ' . (int) $p['age'] . ' anos'
           . ($gp ? ' · ' . avg($p['s_pts'] ?? 0, $gp) . ' pts' : '');
    $noMin = !$auto && $st && $min === 0;
    $out   = !$auto && !$st && $min > 0 && $rest === 0 && !isset($plays[$id]);
    return '<article class="pcard rot-card' . ($st ? ' starter' : '') . ($eff === 0 ? ' off' : '') . '"'
        . ' data-pid="' . $id . '" data-ovr="' . (int) $p['ovr'] . '" data-rest="' . $rest . '">'
        . '<a class="rc-who" href="' . url('player', ['id' => $id]) . '" data-player="' . $id . '">'
        . player_photo((int) ($p['nba_id'] ?? 0), $name, $color, 'md', 'face', $id, (string) $p['pos'])
        . '<span class="pc-txt"><b>' . e($name) . '</b><small>' . $line . '</small>'
        . '<span class="pc-flags">' . $flags($p)
        . '<span data-st="nomin"' . ($noMin ? '' : ' hidden') . '>' . chip('Sem minutos', 'warn', 'exclamation-triangle-fill') . '</span>'
        . '<span data-st="out"' . ($out ? '' : ' hidden') . '>' . chip('Fica fora: só 8 jogam', 'warn', 'exclamation-triangle-fill') . '</span>'
        . '</span></span></a>'
        . ovr_badge($p['ovr'])
        . '<div class="rc-ctrl">'
        . '<label class="rc-start"><input type="checkbox" name="starter[]" value="' . $id . '"' . ($st ? ' checked' : '') . '><span>Titular</span></label>'
        . '<span class="rc-min">'
        . '<button type="button" class="btn btn-icon" data-step="-2" aria-label="Tirar 2 minutos de ' . e($name) . '">' . bi('dash-lg') . '</button>'
        . '<input class="input" type="number" name="min[' . $id . ']" value="' . ($auto ? '' : $min) . '"'
        . ' placeholder="' . ($auto ? (int) ($autoMin[$id] ?? 0) : '') . '" min="0" max="48" inputmode="numeric" aria-label="Minutos de ' . e($name) . '">'
        . '<button type="button" class="btn btn-icon" data-step="2" aria-label="Dar 2 minutos a ' . e($name) . '">' . bi('plus-lg') . '</button>'
        . '<span class="rc-unit dim">min</span></span>'
        . '</div>'
        . meter($eff / 48 * 100)
        . '</article>';
};

// Lesionado: só a carta, sem controles.
$injCard = function (array $p) use ($color, $flags): string {
    $id   = (int) $p['id'];
    $name = (string) $p['name'];
    $desc = trim((string) ($p['injury_desc'] ?? ''));
    return '<article class="pcard rot-card inj">'
        . '<a class="rc-who" href="' . url('player', ['id' => $id]) . '" data-player="' . $id . '">'
        . player_photo((int) ($p['nba_id'] ?? 0), $name, $color, 'md', 'face', $id, (string) $p['pos'])
        . '<span class="pc-txt"><b>' . e($name) . '</b><small><span class="pos-tag">' . e((string) $p['pos']) . '</span> '
        . ($desc !== '' ? e($desc) : (int) $p['age'] . ' anos') . '</small>'
        . '<span class="pc-flags">' . $flags($p) . '</span></span></a>'
        . ovr_badge($p['ovr'])
        . '</article>';
};

$slot = '<div class="rot-slot">' . bi('person-plus') . '<b>Vaga de titular</b><small>Marque “Titular” num reserva</small></div>';

$tile = fn(string $key, string $label, string $value, string $sub, string $tone): string =>
    '<div class="stat" data-sum="' . e($key) . '"><small>' . e($label) . '</small><b' . ($tone !== '' ? ' class="' . e($tone) . '"' : '') . '>'
    . e($value) . '</b><span>' . e($sub) . '</span></div>';

page_head('Escalação', [
    'eyebrow' => 'Elenco',
    'sub' => 'Escolha os 5 titulares e divida os minutos. Toque num jogador para conversar, dar descanso ou negociar.',
]);
?>
<div class="stack">
<form method="post" action="<?= url('home', ['action' => 'save-rotation']) ?>" id="rotForm" class="stack">
  <input type="hidden" name="team" value="<?= $gmId ?>">

  <section class="panel rot-sum">
    <div class="stats">
      <?= $tile('start', 'Titulares', $nStart . '/5', 'precisa de 5', $nStart === 5 ? 'pos' : 'warn') ?>
      <?= $tile('rot', 'Na rotação', (string) $playing, $auto ? 'automático' : ($inRot > 8 ? $inRot . ' com minutos' : 'jogam até 8'),
          $playing >= 5 && $playing <= 8 && ($auto || $inRot <= 8) ? 'pos' : 'warn') ?>
      <?= $tile('min', 'Minutos', $auto ? 'Auto' : (string) $totalMin,
          $auto ? 'o jogo divide os 240' : ($totalMin === 240 ? 'fechou 240' : 'o jogo ajusta para 240'), !$auto && $totalMin === 240 ? 'pos' : '') ?>
      <?= $tile('chem', 'Química', (string) (int) ($t['chemistry'] ?? 0), 'do elenco', '') ?>
    </div>
    <div class="rot-issues" id="rotAuto"<?= $auto ? '' : ' hidden' ?>><?= note('<b>Minutos automáticos.</b> O jogo divide o tempo entre os 8 de cima. Mexa nos minutos de alguém para controlar a rotação você mesmo.', 'info', 'magic') ?></div>
    <div class="rot-issues" id="rotIssues"<?= $issues ? '' : ' hidden' ?>><?= note('<span id="rotIssuesTxt">' . e(implode(' ', $issues)) . '</span>', 'warn') ?></div>
    <div class="rot-bar">
      <?php if ($next): ?>
        <a class="more" href="<?= url('manage') ?>#scouting">Próximo jogo: <?= !empty($next['is_home']) ? 'vs' : '@' ?> <?= e((string) $next['opp_abbr']) ?> · <?= e(League::dateLabel((int) $next['day'])) ?><?= bi('chevron-right') ?></a>
      <?php else: ?>
        <span class="dim">Sem jogo marcado.</span>
      <?php endif; ?>
      <span class="row">
        <span data-dirty hidden><?= chip('Não salvo', 'warn', 'pencil-fill') ?></span>
        <button class="btn btn-ghost" type="button" id="rotAutoBtn"<?= $auto ? ' hidden' : '' ?>><?= bi('magic') ?>Automático</button>
        <button class="btn btn-team" type="submit"><?= bi('check2-circle') ?>Salvar rotação</button>
      </span>
    </div>
  </section>

  <section class="panel hot">
    <?= panel_head('Quinteto titular', ['icon' => 'star-fill', 'meta' => 'Começam o jogo']) ?>
    <div class="pcards rot-five" id="rotFive">
      <?php foreach ($starters as $p) echo $card($p); ?>
      <?php for ($i = count($starters); $i < 5; $i++) echo $slot; ?>
    </div>
  </section>

  <section class="panel">
    <?= panel_head('Banco de reservas', ['icon' => 'people-fill', 'meta' => count($bench) . (count($bench) === 1 ? ' jogador' : ' jogadores')]) ?>
    <div class="rot-note"><?= note('Entram em quadra até 8: os titulares e os reservas de maior OVR que estiverem disponíveis. Se a soma não fechar 240 minutos, o jogo ajusta mantendo a proporção.', 'info') ?></div>
    <div class="pcards rot-bench" id="rotBench">
      <?php foreach ($bench as $p) echo $card($p); ?>
    </div>
  </section>

  <div class="rot-save">
    <span data-dirty hidden><?= chip('Alterações não salvas', 'warn', 'pencil-fill') ?></span>
    <button class="btn btn-team btn-lg" type="submit"><?= bi('check2-circle') ?>Salvar rotação</button>
  </div>
</form>

<?php if ($injured): ?>
<section class="panel">
  <?= panel_head('Departamento médico', ['icon' => 'bandaid-fill', 'meta' => 'Voltam sozinhos quando sararem']) ?>
  <div class="pcards">
    <?php foreach ($injured as $p) echo $injCard($p); ?>
  </div>
</section>
<?php endif; ?>
</div>

<div class="fba-dialog pm" id="pmSheet" hidden>
  <div class="fd-box pm-box" role="dialog" aria-modal="true" aria-labelledby="pmTitle">
    <button type="button" class="btn btn-ghost btn-icon pm-x" data-pm-close aria-label="Fechar"><?= bi('x-lg') ?></button>
    <div id="pmBody"></div>
  </div>
</div>
<template id="rotSlotTpl"><?= $slot ?></template>
<?php foreach (array_merge($starters, $bench, $injured) as $p):
    $id    = (int) $p['id'];
    $inj   = (int) ($p['injury_games'] ?? 0);
    $eff   = $inj ? 0 : $shown($p);
    $st    = !$inj && (int) ($p['is_starter'] ?? 0) === 1;
    $mor   = (int) ($p['morale'] ?? 75);
    $focus = !empty($p['dev_focus']);
    $onBl  = in_array($id, $block, true);
    $role  = $inj ? 'Lesionado' : ($st ? 'Titular' : ($eff > 0 ? 'Reserva' : 'Fora da rotação'));
    $ht    = (int) ($p['ht'] ?? 0) > 0 ? number_format((int) $p['ht'] / 100, 2, ',', '') . ' m' : '';
    $pg    = potGrade($p);
    $attrs = [
        'Ataque'    => (int) round(((int) $p['ins'] + (int) $p['mid'] + (int) $p['thr'] + (int) $p['pmk']) / 4),
        'Defesa'    => (int) $p['def'],
        'Atletismo' => (int) $p['ath'],
        'Fôlego'    => (int) $p['sta'],
    ];
?>
<template id="pm-<?= $id ?>">
  <div class="pm-head">
    <?= player_photo((int) ($p['nba_id'] ?? 0), (string) $p['name'], $color, 'md', 'pm-face', $id, (string) $p['pos']) ?>
    <div class="pm-id">
      <span class="eyebrow pm-role"><?= e($role) ?><?= $eff > 0 ? ' · ' . $eff . ' min' . ($auto ? ' (auto)' : '') : '' ?></span>
      <h3 class="fd-title" id="pmTitle"><?= e((string) $p['name']) ?></h3>
      <span class="pm-meta"><?= e((string) $p['pos']) ?> · <?= (int) $p['age'] ?> anos<?= $ht !== '' ? ' · ' . $ht : '' ?></span>
    </div>
    <?= ovr_badge($p['ovr'], 'lg') ?>
  </div>
  <div class="row pm-chips">
    <?= chip('Moral ' . $mor, $mor >= 85 ? 'ok' : ($mor >= 65 ? '' : 'warn'), $mor >= 65 ? 'emoji-smile' : 'emoji-frown') ?>
    <?= $flags($p, false) ?>
    <?= $pg !== '' ? chip('Potencial ' . $pg, 'info', 'graph-up-arrow') : '' ?>
  </div>
  <div class="pm-attrs">
    <?php foreach ($attrs as $label => $v): ?>
    <div class="pm-attr">
      <div class="spread"><span class="label"><?= e($label) ?></span><span class="pm-av"><span class="grade <?= gradeClass($v) ?>"><?= grade($v) ?></span><?= $v ?></span></div>
      <?= meter($v) ?>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="qlinks pm-acts">
    <a class="qlink" href="<?= url('home', ['action' => 'boost-morale', 'pid' => $id, 'back' => 'lineup']) ?>"><?= bi('chat-heart-fill') ?><span><b>Conversar</b><small>A moral sobe 8 pontos, uma vez por semana</small></span></a>
    <?php if (!$inj): ?>
    <a class="qlink" href="<?= url('home', ['action' => 'rest-player', 'pid' => $id]) ?>"><?= bi('moon-stars-fill') ?><span><b>Dar descanso</b><small>Fica fora dos 2 próximos jogos e perde 5 min</small></span></a>
    <?php endif; ?>
    <?php if ((int) $p['age'] <= 25 || $focus): ?>
    <a class="qlink" href="<?= url('home', ['action' => 'dev-focus', 'pid' => $id]) ?>"><?= bi('bullseye') ?><span><b><?= $focus ? 'Tirar do foco de treino' : 'Foco de treino' ?></b><small><?= $focus ? 'Volta ao ritmo normal de evolução' : 'Evolui mais na entressafra. Vale para até ' . League::DEV_FOCUS_MAX . '.' ?></small></span></a>
    <?php endif; ?>
    <a class="qlink" href="<?= url('home', ['action' => 'trade-block', 'pid' => $id]) ?>"><?= bi('megaphone-fill') ?><span><b><?= $onBl ? 'Tirar da vitrine' : 'Pôr na vitrine de trocas' ?></b><small><?= $onBl ? 'Sai da lista de negociáveis' : 'A liga manda propostas na caixa de entrada' ?></small></span></a>
    <a class="qlink" href="<?= url('player', ['id' => $id]) ?>"><?= bi('person-vcard-fill') ?><span><b>Ficha completa</b><small>Atributos, números e carreira</small></span></a>
    <a class="qlink pm-danger" href="<?= url('home', ['action' => 'release', 'pid' => $id, 'back' => 'lineup']) ?>"<?= link_attrs(['confirm' => [
        'text' => 'Dispensar ' . $p['name'] . '? Ele vira agente livre e o salário sai da folha na hora.',
        'title' => 'Dispensar jogador', 'ok' => 'Dispensar', 'danger' => true]]) ?>><?= bi('box-arrow-right') ?><span><b>Dispensar</b><small>Vira agente livre e sai da folha</small></span></a>
  </div>
</template>
<?php endforeach; ?>

<script>
(function () {
  var form = document.getElementById('rotForm');
  if (!form) return;
  var AUTO = <?= json_encode($autoDist) ?>; // SimEngine::MIN_DIST
  var five = document.getElementById('rotFive');
  var bench = document.getElementById('rotBench');
  var slotTpl = document.getElementById('rotSlotTpl');
  var issBox = document.getElementById('rotIssues');
  var issTxt = document.getElementById('rotIssuesTxt');
  var autoBox = document.getElementById('rotAuto');
  var autoBtn = document.getElementById('rotAutoBtn');
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };
  var clamp = function (v) { return Math.max(0, Math.min(48, v)); };

  // ─── rotação: resumo ao vivo, titulares mudam de quadro, minutos ±2 ───
  function minutesOf(inp) {
    var v = parseInt(inp.value, 10);
    return isNaN(v) ? 0 : clamp(v);
  }
  function sig() {
    return Array.prototype.map.call(form.querySelectorAll('input[name="starter[]"], .rot-card input[type=number]'), function (el) {
      return el.name + ':' + (el.type === 'checkbox' ? (el.checked ? 1 : 0) : minutesOf(el));
    }).sort().join('|');
  }
  var initial = sig();
  var dirty = false;
  var lastAuto = false;

  function setTile(key, value, sub, tone) {
    var el = form.querySelector('[data-sum="' + key + '"]');
    if (!el) return;
    var b = el.querySelector('b');
    b.textContent = value;
    b.className = tone;
    el.querySelector('span').textContent = sub;
  }
  function syncSlots() {
    var n = five.querySelectorAll('.rot-card').length;
    var slots = five.querySelectorAll('.rot-slot');
    var want = Math.max(0, 5 - n);
    for (var i = slots.length; i < want; i++) five.appendChild(slotTpl.content.cloneNode(true));
    for (var j = want; j < slots.length; j++) slots[j].remove();
  }
  function recalc() {
    var cards = Array.prototype.slice.call(form.querySelectorAll('.rot-card'));
    var nStart = 0, total = 0, inRot = 0, avail = [];
    cards.forEach(function (c) {
      c._inp = c.querySelector('input[type=number]');
      c._st = c.querySelector('input[name="starter[]"]').checked ? 1 : 0;
      c._m = minutesOf(c._inp);
      if (c._st) nStart++;
      if (c._m > 0) { total += c._m; inRot++; }
      if (c.getAttribute('data-rest') === '0') avail.push(c);
    });
    // mesma ordem do motor: titular primeiro, depois maior OVR
    avail.sort(function (a, b) {
      return (b._st - a._st) || (+b.getAttribute('data-ovr') - +a.getAttribute('data-ovr')) || (+a.getAttribute('data-pid') - +b.getAttribute('data-pid'));
    });
    var plays = avail.slice(0, 8);
    var auto = total === 0;
    var shownCount = 0;
    cards.forEach(function (c) {
      var idx = plays.indexOf(c);
      var autoM = idx >= 0 ? (AUTO[idx] || 0) : 0;
      c._shown = auto ? autoM : (idx >= 0 ? c._m : 0);
      if (c._shown > 0) shownCount++;
      c._inp.placeholder = auto ? String(autoM) : '';
      c.classList.toggle('starter', c._st === 1);
      c.classList.toggle('off', c._shown === 0);
      var bar = c.querySelector('.meter > span');
      if (bar) bar.style.width = (c._shown / 48 * 100) + '%';
      c.querySelector('[data-st="nomin"]').hidden = !(!auto && c._st && c._m === 0);
      c.querySelector('[data-st="out"]').hidden = !(!auto && !c._st && c._m > 0 && c.getAttribute('data-rest') === '0' && idx < 0);
    });
    var playing = auto ? plays.length : shownCount;
    lastAuto = auto;
    setTile('start', nStart + '/5', 'precisa de 5', nStart === 5 ? 'pos' : 'warn');
    setTile('rot', String(playing), auto ? 'automático' : (inRot > 8 ? inRot + ' com minutos' : 'jogam até 8'),
      playing >= 5 && playing <= 8 && (auto || inRot <= 8) ? 'pos' : 'warn');
    setTile('min', auto ? 'Auto' : String(total), auto ? 'o jogo divide os 240' : (total === 240 ? 'fechou 240' : 'o jogo ajusta para 240'),
      !auto && total === 240 ? 'pos' : '');
    var iss = [];
    if (nStart !== 5) iss.push('Marque exatamente 5 titulares (agora: ' + nStart + ').');
    if (!auto && inRot < 5) iss.push('Dê minutos a pelo menos 5 jogadores, ou volte ao automático.');
    else if (!auto && inRot > 8) iss.push('Só 8 entram em quadra: ' + (inRot - 8 === 1 ? '1 jogador com minutos vai ficar fora.' : (inRot - 8) + ' jogadores com minutos vão ficar fora.'));
    issTxt.textContent = iss.join(' ');
    issBox.hidden = iss.length === 0;
    autoBox.hidden = !auto;
    autoBtn.hidden = auto;
    dirty = sig() !== initial;
    each(document.querySelectorAll('[data-dirty]'), function (el) { el.hidden = !dirty; });
  }
  // Saindo do automático: os minutos padrão viram o ponto de partida de todo mundo.
  function materialize(except) {
    each(form.querySelectorAll('.rot-card input[type=number]'), function (inp) {
      if (inp !== except) inp.value = String(clamp(parseInt(inp.placeholder, 10) || 0));
    });
  }

  form.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-step]') : null;
    if (!btn) return;
    var inp = btn.parentNode.querySelector('input[type=number]');
    var step = parseInt(btn.getAttribute('data-step'), 10);
    if (lastAuto) {
      var base = parseInt(inp.placeholder, 10) || 0;
      materialize(inp);
      inp.value = String(clamp(base + step));
    } else {
      inp.value = String(clamp(minutesOf(inp) + step));
    }
    recalc();
  });
  autoBtn.addEventListener('click', function () {
    each(form.querySelectorAll('.rot-card input[type=number]'), function (inp) { inp.value = ''; });
    recalc();
  });
  form.addEventListener('input', function (e) {
    if (e.target.type !== 'number') return;
    if (lastAuto && e.target.value !== '') materialize(e.target);
    recalc();
  });
  form.addEventListener('change', function (e) {
    var el = e.target;
    if (el.type === 'number') { if (el.value !== '') el.value = String(minutesOf(el)); recalc(); return; }
    if (el.name !== 'starter[]') return;
    var card = el.closest('.rot-card');
    if (el.checked) five.insertBefore(card, five.querySelector('.rot-slot'));
    else bench.insertBefore(card, bench.firstChild);
    syncSlots();
    recalc();
    card.classList.remove('moved');
    void card.offsetWidth;
    card.classList.add('moved');
    try { el.focus({ preventScroll: true }); } catch (err) {}
    var r = card.getBoundingClientRect();
    if (r.top < 90 || r.bottom > window.innerHeight - 190) card.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
  });

  // Salvar: o servidor devolve para a Diretoria; aqui a resposta é seguida e
  // o jogador volta para a escalação com o aviso. Sem fetch, o POST segue normal.
  form.addEventListener('submit', function (e) {
    if (!window.fetch || !window.FormData || typeof URL !== 'function') return;
    e.preventDefault();
    var data = new FormData(form);
    each(form.querySelectorAll('button[type=submit]'), function (b) { b.disabled = true; });
    fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (res) {
      var u = new URL(res.url);
      dirty = false;
      if (u.searchParams.get('saved') !== 'rotation') { window.location.href = res.url; return; }
      var w = (u.searchParams.get('w') || '').split('|').filter(Boolean);
      var dest = new URL(window.location.href);
      dest.search = '';
      dest.hash = '';
      dest.searchParams.set('p', 'lineup');
      if (w.length) dest.searchParams.set('err', 'Rotação salva, mas atenção: ' + w.join(' '));
      else dest.searchParams.set('msg', 'Rotação salva.');
      window.location.href = dest.toString();
    }).catch(function () {
      dirty = false;
      form.submit();
    });
  });

  // Mudou e não salvou: qualquer link para fora (inclusive o Próxima) pergunta antes.
  document.addEventListener('click', function (e) {
    if (!dirty || !window.fbaConfirm || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || a.hasAttribute('data-player') || a.target === '_blank') return;
    if ((a.getAttribute('href') || '').charAt(0) === '#') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    window.fbaConfirm('Você mexeu na rotação e ainda não salvou. Sair e perder as mudanças?', {
      title: 'Rotação não salva', ok: 'Sair sem salvar', danger: true
    }).then(function (ok) {
      if (!ok) return;
      dirty = false;
      a.click();
    });
  }, true);

  // ─── ficha rápida do jogador (folha de baixo no celular) ───
  var sheet = document.getElementById('pmSheet');
  var body = document.getElementById('pmBody');
  var lastFocus = null;
  function openSheet(pid) {
    var tpl = document.getElementById('pm-' + pid);
    if (!tpl || !sheet) return false;
    body.innerHTML = '';
    body.appendChild(tpl.content.cloneNode(true));
    // papel e minutos do jeito que estão agora na tela (ainda sem salvar)
    var card = form.querySelector('.rot-card[data-pid="' + pid + '"]');
    var role = body.querySelector('.pm-role');
    if (card && role && card._inp) {
      var m = card._shown || 0;
      role.textContent = (card._st ? 'Titular' : (m > 0 ? 'Reserva' : 'Fora da rotação'))
        + (m > 0 ? ' · ' + m + ' min' + (lastAuto ? ' (auto)' : '') : '');
    }
    lastFocus = document.activeElement;
    sheet.hidden = false;
    document.documentElement.classList.add('pm-lock');
    var x = sheet.querySelector('[data-pm-close]');
    try { x.focus({ preventScroll: true }); } catch (err) {}
    return true;
  }
  function closeSheet() {
    if (!sheet || sheet.hidden) return;
    sheet.hidden = true;
    body.innerHTML = '';
    document.documentElement.classList.remove('pm-lock');
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (err) {} }
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('[data-player]') : null;
    if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (openSheet(a.getAttribute('data-player'))) e.preventDefault();
  });
  if (sheet) {
    sheet.addEventListener('click', function (e) {
      if (e.target === sheet || (e.target.closest && e.target.closest('[data-pm-close]'))) closeSheet();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || !sheet || sheet.hidden) return;
    if (document.querySelector('.fba-dialog:not(#pmSheet)')) return; // a confirmação fecha primeiro
    closeSheet();
  });

  recalc();
})();
</script>
<?php render_footer(); ?>
