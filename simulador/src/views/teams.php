<?php
require_once dirname(__DIR__) . '/helpers.php';

// Endereços antigos: ?p=teams&id=N abria um time e ?p=teams&q= buscava jogador
if ((int) ($_GET['id'] ?? 0) > 0) { header('Location: ' . url('team', ['id' => (int) $_GET['id']])); exit; }
$oldQ = trim((string) ($_GET['q'] ?? ''));
if ($oldQ !== '') { header('Location: ' . url('search', ['q' => $oldQ])); exit; }

$gmId     = (int) League::gmTeam();
$gm       = $gmId ? League::team($gmId) : null;
$confName = ['E' => 'Leste', 'W' => 'Oeste'];
$divPt    = ['Atlantic' => 'Atlântico', 'Central' => 'Central', 'Southeast' => 'Sudeste',
             'Northwest' => 'Noroeste', 'Pacific' => 'Pacífico', 'Southwest' => 'Sudoeste'];
$confOrder = ($gm && $gm['conf'] === 'W') ? ['W', 'E'] : ['E', 'W'];

$seeds = [];
foreach (['E', 'W'] as $c) foreach (League::standings($c) as $s) $seeds[(int) $s['id']] = (int) $s['seed'];

// Times por conferência e divisão, com o nível do elenco (média dos 8 maiores OVR) e o destaque
$byConf = ['E' => [], 'W' => []];
$level  = [];
$star   = [];
foreach (League::allTeams() as $t) {
    $id = (int) $t['id'];
    $byConf[(string) $t['conf']][(string) $t['div']][] = $t;
    $ovrs = [];
    $best = null;
    foreach (League::roster($id) as $p) {
        $ovrs[] = (int) $p['ovr'];
        if (!$best || (int) $p['ovr'] > (int) $best['ovr']) $best = $p;
    }
    rsort($ovrs);
    $top = array_slice($ovrs, 0, 8);
    $level[$id] = $top ? (int) round(array_sum($top) / count($top)) : 0;
    $star[$id]  = $best;
}
foreach ($byConf as $c => $divs) {
    foreach ($divs as $div => $list) {
        usort($list, fn($a, $b) => ($seeds[(int) $a['id']] ?? 99) <=> ($seeds[(int) $b['id']] ?? 99));
        $byConf[$c][$div] = $list;
    }
}

render_header('Times');
page_head('Times', [
    'eyebrow' => 'Liga · Temporada ' . League::season(),
    'sub' => 'Todas as franquias por conferência e divisão. A carta ao lado de cada time é o nível do elenco, a média dos 8 maiores OVR.',
]);
?>
<div class="grid cols-2">
  <?php foreach ($confOrder as $c): if (empty($byConf[$c])) continue; ?>
  <section class="panel">
    <?= panel_head('Conferência ' . $confName[$c], ['icon' => 'trophy-fill', 'more' => ['Classificação', url('standings')]]) ?>
    <?php foreach ($byConf[$c] as $div => $list): ?>
      <div class="tl-div">
        <div class="sub-h">Divisão <?= e($divPt[$div] ?? $div) ?></div>
        <?php foreach ($list as $t):
          $id   = (int) $t['id'];
          $seed = $seeds[$id] ?? null;
          $s    = $star[$id] ?? null; ?>
          <a class="tl-row<?= $id === $gmId ? ' mine' : '' ?>" href="<?= url('team', ['id' => $id]) ?>">
            <?= team_logo((string) $t['abbr'], (string) ($t['primary_color'] ?? '#333'), 'md') ?>
            <span class="tl-txt">
              <b><?= e(teamFull($t)) ?></b>
              <small><?= (int) $t['wins'] ?>-<?= (int) $t['losses'] ?><?= $seed ? ' · ' . $seed . 'º no ' . $confName[$c] : '' ?><?= $id === $gmId ? ' · seu time' : '' ?></small>
              <?php if ($s): ?><small class="tl-star">Destaque: <?= e($s['name']) ?></small><?php endif; ?>
            </span>
            <?php if ($level[$id] ?? 0): ?><span class="tl-lvl" title="Nível do elenco: média dos 8 maiores OVR"><?= ovr_badge($level[$id], 'sm') ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>
</div>
<?php render_footer(); ?>
