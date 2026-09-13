<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Buscar');
$q = trim((string) ($_GET['q'] ?? ''));
$players = $q !== '' ? League::searchPlayers($q) : [];
$teams = [];
if ($q !== '') {
    $needle = mb_strtolower($q);
    foreach (League::allTeams() as $t) {
        if (str_contains(mb_strtolower($t['city'] . ' ' . $t['name'] . ' ' . $t['abbr']), $needle)) $teams[] = $t;
    }
}
// atalhos de página, pra quem procura uma tela pelo nome
$pages = [
    'Classificação' => 'standings', 'Power Rankings' => 'power', 'Jogos' => 'schedule', 'Líderes' => 'leaders',
    'Folha & Cap' => 'cap', 'Histórico' => 'history', 'Times' => 'teams', 'Meu Time' => 'manage',
    'Escalação' => 'lineup', 'Trocas' => 'trades', 'Agentes Livres' => 'freeagency', 'Mensagens' => 'inbox',
    'Draft' => 'draft', 'Playoffs' => 'playoffs',
];
$pageHits = [];
if ($q !== '') foreach ($pages as $label => $key) { if (str_contains(mb_strtolower($label), mb_strtolower($q))) $pageHits[$label] = $key; }
?>
<h1 class="page-title">🔍 Buscar</h1>
<form method="get" class="search-form">
  <input type="hidden" name="p" value="search">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Jogador, time ou tela (ex.: Tatum, Lakers, trocas)" autofocus>
  <button class="btn btn-primary" type="submit">Buscar</button>
</form>

<?php if ($q === ''): ?>
  <p class="muted">Digite o nome de um jogador, de um time ou de uma tela.</p>
<?php else: ?>
  <?php if ($pageHits): ?>
  <section class="card"><div class="card-head"><h2>Telas</h2></div>
    <div class="cap-actions"><?php foreach ($pageHits as $label => $key): ?><a class="btn" href="<?= url($key) ?>"><?= e($label) ?> →</a><?php endforeach; ?></div>
  </section>
  <?php endif; ?>
  <?php if ($teams): ?>
  <section class="card"><div class="card-head"><h2>Times</h2></div>
    <div class="cap-actions"><?php foreach ($teams as $t): ?><a class="btn" href="<?= url('team', ['id' => $t['id']]) ?>"><?= team_logo($t['abbr'], $t['primary_color'], 'sm') ?> <?= e(teamFull($t)) ?></a><?php endforeach; ?></div>
  </section>
  <?php endif; ?>
  <section class="card"><div class="card-head"><h2>Jogadores (<?= count($players) ?>)</h2></div>
    <?php if (!$players): ?><p class="muted">Nenhum jogador encontrado com "<?= e($q) ?>".</p><?php else: ?>
    <table class="box-table">
      <thead><tr><th>Jogador</th><th>Time</th><th>Pos</th><th class="hide-sm">Idade</th><th>OVR</th><th class="hide-sm">Salário</th><th class="hide-sm">PPG</th></tr></thead>
      <tbody>
      <?php foreach ($players as $p): ?>
        <tr>
          <td class="bx-name"><a href="<?= url('player', ['id' => $p['id']]) ?>"><?= e($p['name']) ?></a></td>
          <td><span class="dot" style="background:<?= e($p['primary_color']) ?>"></span><?= e($p['abbr']) ?></td>
          <td><?= e($p['pos']) ?></td>
          <td class="num hide-sm"><?= (int)$p['age'] ?></td>
          <td><span class="ovr ovr-<?= $p['ovr']>=90?'elite':($p['ovr']>=80?'star':($p['ovr']>=75?'good':'role')) ?>"><?= (int)$p['ovr'] ?></span></td>
          <td class="num hide-sm"><?= money($p['salary'] ?? 0) ?></td>
          <td class="num hide-sm"><?= avg($p['s_pts'] ?? 0, $p['gp'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php render_footer(); ?>
