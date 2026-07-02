<?php
require_once dirname(__DIR__) . '/helpers.php';
$gmId = League::gmTeam();
render_header('Trocas');
if (!$gmId) {
    echo '<p class="muted">Escolha uma franquia primeiro no <a href="' . url('gmselect') . '">Modo GM</a>.</p>';
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
$msg     = $_GET['msg'] ?? '';

// Contraproposta recebida
$counter = [];
if (!empty($_GET['counter'])) {
    $counter = array_filter(array_map('intval', explode(',', $_GET['counter'])));
}
$counterGive = !empty($_GET['cgive']) ? array_filter(array_map('intval', explode(',', $_GET['cgive']))) : [];
?>

<!-- Topbar da página -->
<div class="card-head page" style="margin-bottom:18px">
  <h1 class="page-title">⇄ Central de Trocas</h1>
  <form method="get" style="display:flex;align-items:center;gap:10px">
    <input type="hidden" name="p" value="trades">
    <label style="font-size:13px;color:var(--muted);font-weight:700">Negociar com</label>
    <select name="ai" onchange="this.form.submit()" class="season-select">
      <?php foreach ($others as $o): ?>
        <option value="<?= $o['id'] ?>" <?= $o['id']==$aiId?'selected':'' ?>>
          <?= e($o['city'].' '.$o['name']) ?> · Folha <?= money(League::teamPayroll((int)$o['id'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($msg): ?>
<div class="trade-msg <?= str_starts_with($msg,'✅')?'ok':'no' ?>" style="margin-bottom:16px"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Contraproposta recebida -->
<?php if ($counter): ?>
<div class="counter-box" style="margin-bottom:18px">
  <div class="counter-title">🤝 <?= e($ai['city'].' '.$ai['name']) ?> fez uma contraproposta:</div>
  <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:12px;align-items:center">
    <div>
      <p style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-bottom:6px">Você enviaria</p>
      <div class="counter-players">
        <?php foreach ($myRoster as $p):
          if (!in_array((int)$p['id'], $counterGive)) continue; ?>
          <span class="counter-chip"><?= e($p['name']) ?> <span class="muted"><?= $p['ovr'] ?></span></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="font-size:24px;text-align:center">⇄</div>
    <div>
      <p style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-bottom:6px">Você receberia</p>
      <div class="counter-players">
        <?php foreach ($aiRoster as $p):
          if (!in_array((int)$p['id'], $counter)) continue; ?>
          <span class="counter-chip"><?= e($p['name']) ?> <span class="muted"><?= $p['ovr'] ?></span></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div style="display:flex;gap:10px;margin-top:14px">
    <form method="post" action="<?= url('home', ['action'=>'propose-trade']) ?>" style="flex:1">
      <input type="hidden" name="ai_team" value="<?= $aiId ?>">
      <?php foreach ($counterGive as $cid): ?>
        <input type="hidden" name="give[]" value="<?= $cid ?>">
      <?php endforeach; ?>
      <?php foreach ($counter as $cid): ?>
        <input type="hidden" name="get[]" value="<?= $cid ?>">
      <?php endforeach; ?>
      <button class="btn btn-primary" style="width:100%;justify-content:center" type="submit">✅ Aceitar contraproposta</button>
    </form>
    <a class="btn" href="<?= url('trades',['ai'=>$aiId]) ?>" style="flex:1;text-align:center">❌ Recusar</a>
  </div>
</div>
<?php endif; ?>

<!-- Trade builder principal -->
<form method="post" action="<?= url('home', ['action'=>'propose-trade']) ?>" id="tradeForm">
  <input type="hidden" name="ai_team" value="<?= $aiId ?>">

  <div class="trade-page">
    <!-- Coluna GM -->
    <div>
      <div class="trade-col-header">
        <?= team_logo($gm['abbr'], $gm['primary_color'], 'md') ?>
        <div>
          <div class="trade-col-title"><?= e($gm['city'].' '.$gm['name']) ?></div>
          <div class="trade-col-record">Você envia · Folha <?= money(League::teamPayroll($gmId)) ?></div>
        </div>
      </div>
      <div id="myPlayers">
        <?php foreach ($myRoster as $p):
          $ovrCls = $p['ovr']>=90?'ovr-elite':($p['ovr']>=80?'ovr-star':($p['ovr']>=75?'ovr-good':'ovr-role'));
        ?>
        <label class="trade-player-card" data-val="<?= tradeValue($p) ?>">
          <input type="checkbox" name="give[]" value="<?= $p['id'] ?>">
          <?= player_photo((int)($p['nba_id']??0), $p['name'], $gm['primary_color'], 'sm', 'tpc-photo') ?>
          <div class="tpc-info">
            <span class="tpc-name"><?= e($p['name']) ?></span>
            <span class="tpc-meta"><?= e($p['pos']) ?> · <?= $p['age'] ?> anos · <?= money($p['salary'] ?? 0) ?></span>
          </div>
          <span class="tpc-ovr <?= $ovrCls ?>"><?= $p['ovr'] ?></span>
          <span class="tpc-check">✓</span>
        </label>
        <?php endforeach; ?>
        <?php if ($myPicks): ?>
          <p style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin:12px 0 6px">Picks</p>
          <?php foreach ($myPicks as $pk):
            $via = ((int)$pk['original_team_id'] !== (int)$pk['owner_team_id']) ? ('via '.$pk['orig_abbr']) : 'própria';
          ?>
          <label class="trade-player-card" data-val="15">
            <input type="checkbox" name="give_pick[]" value="<?= $pk['id'] ?>">
            <span style="font-size:20px;margin-right:4px">📋</span>
            <div class="tpc-info">
              <span class="tpc-name">R<?= $pk['round'] ?> · <?= League::draftYearLabel((int)$pk['year']) ?></span>
              <span class="tpc-meta"><?= $via ?></span>
            </div>
            <span class="tpc-check">✓</span>
          </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Setas centrais -->
    <div class="trade-arrows">
      <span class="trade-arrow">◀</span>
      <span class="trade-arrow">▶</span>
    </div>

    <!-- Coluna AI -->
    <div>
      <div class="trade-col-header">
        <?= team_logo($ai['abbr'], $ai['primary_color'], 'md') ?>
        <div>
          <div class="trade-col-title"><?= e($ai['city'].' '.$ai['name']) ?></div>
          <div class="trade-col-record">Você recebe · Folha <?= money(League::teamPayroll($aiId)) ?></div>
        </div>
      </div>
      <div id="aiPlayers">
        <?php foreach ($aiRoster as $p):
          $ovrCls = $p['ovr']>=90?'ovr-elite':($p['ovr']>=80?'ovr-star':($p['ovr']>=75?'ovr-good':'ovr-role'));
        ?>
        <label class="trade-player-card" data-val="<?= tradeValue($p) ?>">
          <input type="checkbox" name="get[]" value="<?= $p['id'] ?>">
          <?= player_photo((int)($p['nba_id']??0), $p['name'], $ai['primary_color'], 'sm', 'tpc-photo') ?>
          <div class="tpc-info">
            <span class="tpc-name"><?= e($p['name']) ?></span>
            <span class="tpc-meta"><?= e($p['pos']) ?> · <?= $p['age'] ?> anos · <?= money($p['salary'] ?? 0) ?></span>
          </div>
          <span class="tpc-ovr <?= $ovrCls ?>"><?= $p['ovr'] ?></span>
          <span class="tpc-check">✓</span>
        </label>
        <?php endforeach; ?>
        <?php if ($aiPicks): ?>
          <p style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin:12px 0 6px">Picks</p>
          <?php foreach ($aiPicks as $pk):
            $via = ((int)$pk['original_team_id'] !== (int)$pk['owner_team_id']) ? ('via '.$pk['orig_abbr']) : 'própria';
          ?>
          <label class="trade-player-card" data-val="15">
            <input type="checkbox" name="get_pick[]" value="<?= $pk['id'] ?>">
            <span style="font-size:20px;margin-right:4px">📋</span>
            <div class="tpc-info">
              <span class="tpc-name">R<?= $pk['round'] ?> · <?= League::draftYearLabel((int)$pk['year']) ?></span>
              <span class="tpc-meta"><?= $via ?></span>
            </div>
            <span class="tpc-check">✓</span>
          </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Trade summary ao vivo -->
  <div class="trade-summary" id="tradeSummary">
    <div class="trade-summary-title">📊 Análise da troca</div>
    <div class="trade-value-bars">
      <span class="tvb-label" id="myValLbl" style="color:<?= e($gm['primary_color']) ?>"><?= e($gm['abbr']) ?></span>
      <div class="tvb-bar"><div class="tvb-fill" id="myValBar" style="background:<?= e($gm['primary_color']) ?>;width:0%"></div></div>
      <span class="tvb-num" id="myValNum">0</span>
    </div>
    <div class="trade-value-bars">
      <span class="tvb-label" id="aiValLbl" style="color:<?= e($ai['primary_color']) ?>"><?= e($ai['abbr']) ?></span>
      <div class="tvb-bar"><div class="tvb-fill" id="aiValBar" style="background:<?= e($ai['primary_color']) ?>;width:0%"></div></div>
      <span class="tvb-num" id="aiValNum">0</span>
    </div>
    <div class="trade-verdict neutral" id="tradeVerdict">Selecione jogadores para ver a análise</div>
  </div>

  <div style="text-align:center;margin-top:20px;display:flex;gap:12px;justify-content:center">
    <button class="btn btn-primary btn-lg" type="submit">⇄ Propor troca</button>
  </div>
  <p class="legend" style="text-align:center;margin-top:8px">
    A IA avalia valor, idade, potencial e necessidade de posição. Se recusar, pode enviar uma contraproposta.
  </p>
</form>

<script>
(function(){
  // Toggle visual dos cards
  document.querySelectorAll('.trade-player-card').forEach(card => {
    const cb = card.querySelector('input[type=checkbox]');
    cb.addEventListener('change', () => {
      card.classList.toggle('selected', cb.checked);
      updateSummary();
    });
    card.addEventListener('click', e => {
      if (e.target !== cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); }
    });
  });

  function getVal(side) {
    let v = 0;
    document.querySelectorAll('#' + side + ' .trade-player-card.selected').forEach(c => {
      v += parseFloat(c.dataset.val || 0);
    });
    return v;
  }

  function updateSummary() {
    const mv = getVal('myPlayers'), av = getVal('aiPlayers');
    const max = Math.max(mv, av, 1);
    document.getElementById('myValBar').style.width = (mv/max*100) + '%';
    document.getElementById('aiValBar').style.width = (av/max*100) + '%';
    document.getElementById('myValNum').textContent = Math.round(mv);
    document.getElementById('aiValNum').textContent = Math.round(av);

    const v = document.getElementById('tradeVerdict');
    if (mv === 0 && av === 0) {
      v.className = 'trade-verdict neutral'; v.textContent = 'Selecione jogadores para ver a análise';
    } else {
      const diff = mv > 0 ? (av - mv) / mv : 1;
      if (Math.abs(diff) < 0.12) {
        v.className = 'trade-verdict fair'; v.textContent = '✅ Troca justa — boa chance de aprovação';
      } else if (diff > 0.12) {
        v.className = 'trade-verdict fair'; v.textContent = '🔥 Você leva vantagem — aprovação provável';
      } else if (diff < -0.30) {
        v.className = 'trade-verdict unfair'; v.textContent = '❌ Muito desfavorável — provável contraproposta';
      } else {
        v.className = 'trade-verdict neutral'; v.textContent = '🤝 Ligeiramente desfavorável — pode gerar contraproposta';
      }
    }
  }
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
