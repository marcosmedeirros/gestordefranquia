<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/PlayerFace.php';

$id = (int) ($_GET['id'] ?? 0);
$p = League::player($id);
if (!$p) { render_header('Jogador'); echo '<p class="muted">Jogador não encontrado.</p>'; render_footer(); exit; }

render_header($p['name']);

$gp       = (int) ($p['gp'] ?? 0);
$log      = League::playerGameLog($id, 20);
$career   = League::playerCareer($id);
$wonAwards = League::playerAwardsWon($id);
$seasPro  = (int) ($p['seasons_pro'] ?? 0);
$potential = (int) ($p['potential'] ?? $p['ovr']);
$ovr      = (int) $p['ovr'];
$injGames = (int) ($p['injury_games'] ?? 0);
$morale   = (int) ($p['morale'] ?? 75);

// Cor do time
$teamColor = $p['primary_color'] ?? '#E4002B';

$attrs = [
  'Interior' => $p['ins'], 'Média' => $p['mid'], '3 Pontos' => $p['thr'],
  'Armação'  => $p['pmk'], 'Rebote' => $p['reb'], 'Defesa'   => $p['def'],
  'Atletismo' => $p['ath'],
];

$ovrClass = $ovr >= 90 ? 'ovr-elite' : ($ovr >= 80 ? 'ovr-star' : ($ovr >= 75 ? 'ovr-good' : 'ovr-role'));
$potClass = $potential > $ovr ? 'potential' : '';

// Rótulos de prêmios
$awardLabels = [
  'MVP' => '🏅 MVP', 'Finals MVP' => '🏆 MVP Finals', 'DPOY' => '🛡️ DPOY',
  'ROY' => '🌟 ROY', 'All-NBA 1' => '⭐ All-NBA 1ª', 'All-NBA 2' => '⭐ All-NBA 2ª', 'All-NBA 3' => '⭐ All-NBA 3ª',
];
?>

<!-- ===== HERO v2 ===== -->
<div class="player-hero-v2">
  <div class="ph2-color" style="background:<?= e($teamColor) ?>"></div>
  <div class="ph2-body">
    <!-- Foto do jogador -->
    <div class="ph2-photo">
      <?= player_photo((int)($p['nba_id'] ?? 0), $p['name'], $teamColor, 'hero') ?>
    </div>
    <div class="ph2-ovr <?= $ovrClass ?>">
      <?= $ovr ?><span>OVR</span>
    </div>
    <div class="ph2-info">
      <h1><?= e($p['name']) ?></h1>
      <p class="ph2-meta">
        <a href="<?= url('team',['id'=>$p['team_id']]) ?>"><?= e($p['city'].' '.$p['team_name']) ?></a>
        · <?= e($p['pos']) ?> · <?= (int)$p['age'] ?> anos · <?= round($p['ht']/100,2) ?>m
        · <?= $seasPro ?> temp. na liga
      </p>
      <?php if (!empty($p['salary'])): ?>
      <p class="ph2-meta" style="margin-top:2px">
        💵 <strong><?= money($p['salary']) ?></strong>/ano
        · 📄 <?= (int)$p['contract_years'] > 0 ? (int)$p['contract_years'].' ano'.((int)$p['contract_years']>1?'s':'').' de contrato' : 'contrato expirando' ?>
      </p>
      <?php endif; ?>
      <div class="ph2-badges">
        <?php if ($seasPro === 0): ?><span class="ph2-badge rookie">🌟 Calouro</span><?php endif; ?>
        <?php if ($injGames > 0): ?><span class="ph2-badge injured">🩹 Lesionado (<?= $injGames ?>j)</span><?php endif; ?>
        <?php if ($potential > $ovr): ?><span class="ph2-badge potential">↑ Pot. <?= $potential ?></span><?php endif; ?>
        <?php if ($morale >= 80): ?><span class="ph2-badge" style="color:var(--green);border-color:rgba(43,212,122,.3)">😊 Alto moral</span><?php endif; ?>
        <?php if ($morale < 55): ?><span class="ph2-badge" style="color:var(--red);border-color:rgba(255,91,103,.3)">😤 Insatisfeito</span><?php endif; ?>
      </div>
    </div>
  </div>
  <?php if ($wonAwards || $gp > 0): ?>
  <div class="ph2-right">
    <?php if ($gp > 0): ?>
    <div class="ph2-gp"><?= $gp ?></div>
    <div class="ph2-gp-lbl">Jogos</div>
    <?php endif; ?>
    <?php if ($wonAwards): ?>
    <div class="ph2-awards" style="margin-top:8px">
      <?php foreach (array_slice($wonAwards, 0, 4) as $aw):
        $short = match($aw['type']) {
          'MVP' => '🏅 MVP', 'Finals MVP' => '🏆', 'DPOY' => '🛡️',
          'ROY' => '🌟 ROY', default => '⭐'
        };
      ?>
      <span class="ph2-award"><?= $short ?> T<?= (int)$aw['season'] ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php /* Stats + Atributos */ ?>
<div class="dashboard">
  <section class="card">
    <div class="card-head"><h2>Temporada atual</h2></div>
    <?php if ($gp): ?>
    <div class="stat-grid">
      <div class="stat-big"><span><?= avg($p['pts'] ?? 0,$gp) ?></span>PPG</div>
      <div class="stat-big"><span><?= avg($p['reb'] ?? 0,$gp) ?></span>RPG</div>
      <div class="stat-big"><span><?= avg($p['ast'] ?? 0,$gp) ?></span>APG</div>
      <div class="stat-big"><span><?= avg($p['stl'] ?? 0,$gp) ?></span>RBPG</div>
      <div class="stat-big"><span><?= avg($p['blk'] ?? 0,$gp) ?></span>TPG</div>
      <div class="stat-big"><span><?= pct($p['fgm'] ?? 0,$p['fga'] ?? 0) ?></span>FG%</div>
      <div class="stat-big"><span><?= pct($p['tpm'] ?? 0,$p['tpa'] ?? 0) ?></span>3P%</div>
      <div class="stat-big"><span><?= $gp ?></span>Jogos</div>
    </div>
    <?php else: ?>
    <p class="muted">Nenhum jogo disputado nesta temporada.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Atributos (2K)</h2></div>
    <div class="attr-list">
      <?php foreach ($attrs as $label => $val): ?>
        <div class="attr-row">
          <span><?= e($label) ?></span>
          <span class="attr-bar"><span style="width:<?= (int)$val ?>%"></span></span>
          <span class="attr-val"><?= (int)$val ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<?php /* Carreira */ ?>
<?php if ($career): ?>
<section class="card" style="margin-bottom:18px">
  <div class="card-head"><h2>Histórico de carreira</h2></div>
  <table class="career-table" style="width:100%">
    <thead>
      <tr>
        <th>Temp.</th><th>Time</th><th>OVR</th><th>GP</th>
        <th class="num">PPG</th><th class="num">RPG</th><th class="num">APG</th>
        <th class="num hide-sm">SPG</th><th class="num hide-sm">BPG</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($career as $s):
      $sgp = max(1, (int)$s['gp']);
      $cur = (League::season() === (int)$s['season'] && !$career); // destaque
    ?>
      <tr>
        <td><?= (int)$s['season'] ?></td>
        <td><span class="muted"><?= e($s['abbr'] ?? '—') ?></span></td>
        <td><span class="ovr <?= $s['ovr']>=90?'ovr-elite':($s['ovr']>=80?'ovr-star':($s['ovr']>=75?'ovr-good':'ovr-role')) ?>"><?= (int)$s['ovr'] ?></span></td>
        <td><?= $sgp ?></td>
        <td class="num"><?= number_format(($s['pts'] ?? 0)/$sgp,1) ?></td>
        <td class="num"><?= number_format(($s['reb'] ?? 0)/$sgp,1) ?></td>
        <td class="num"><?= number_format(($s['ast'] ?? 0)/$sgp,1) ?></td>
        <td class="num hide-sm"><?= number_format(($s['stl'] ?? 0)/$sgp,1) ?></td>
        <td class="num hide-sm"><?= number_format(($s['blk'] ?? 0)/$sgp,1) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php /* Prêmios */ ?>
<?php if ($wonAwards): ?>
<section class="card" style="margin-bottom:18px">
  <div class="card-head"><h2>Prêmios conquistados</h2></div>
  <div class="awards-row">
    <?php foreach ($wonAwards as $aw):
      $label = $awardLabels[$aw['type']] ?? ('⭐ ' . $aw['type']);
    ?>
    <div class="award-card">
      <div class="award-label"><?= e($label) ?></div>
      <div class="award-name" style="font-size:14px">Temporada <?= (int)$aw['season'] ?></div>
      <div class="award-meta"><?= e($aw['abbr'] ?? '') ?><?= $aw['value'] ? ' · ' . e($aw['value']) : '' ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* Game Log */ ?>
<section class="card">
  <div class="card-head"><h2>Últimas partidas</h2></div>
  <?php if (!$log): ?>
    <p class="muted">Nenhum jogo disputado ainda.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="box-table">
    <thead>
      <tr><th>Dia</th><th>Jogo</th><th class="num">MIN</th><th class="num">PTS</th>
          <th class="num">REB</th><th class="num">AST</th><th class="num">BLK</th><th class="num">FG</th><th class="num">3P</th></tr>
    </thead>
    <tbody>
    <?php foreach ($log as $b):
      $opp = ($b['home_id'] == $p['team_id']) ? ('vs '.$b['away_abbr']) : ('@ '.$b['home_abbr']); ?>
      <tr>
        <td><a href="<?= url('game',['id'=>$b['game_id']]) ?>"><?= $b['day'] ?></a></td>
        <td><?= e($opp) ?> <span class="muted"><?= $b['away_pts'].'-'.$b['home_pts'] ?></span></td>
        <td class="num"><?= (int)$b['min'] ?></td>
        <td class="num"><strong><?= $b['pts'] ?></strong></td>
        <td class="num"><?= $b['reb'] ?></td>
        <td class="num"><?= $b['ast'] ?></td>
        <td class="num"><?= $b['blk'] ?? 0 ?></td>
        <td class="num"><?= $b['fgm'].'-'.$b['fga'] ?></td>
        <td class="num"><?= $b['tpm'].'-'.$b['tpa'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<?php render_footer(); ?>
