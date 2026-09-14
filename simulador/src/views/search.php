<?php
require_once dirname(__DIR__) . '/helpers.php';

$q       = trim((string) ($_GET['q'] ?? ''));
$needle  = mb_strtolower($q);
$gmId    = (int) League::gmTeam();
$players = $q !== '' ? League::searchPlayers($q) : [];
$teams   = [];
if ($q !== '') {
    foreach (League::allTeams() as $t) {
        if (str_contains(mb_strtolower($t['city'] . ' ' . $t['name'] . ' ' . $t['abbr'] . ' ' . teamFull($t)), $needle)) $teams[] = $t;
    }
}

// Atalhos de tela para quem procura pelo nome: [rótulo, página, ícone, descrição, outras palavras]
$pages = [
    ['Classificação', 'standings', 'bar-chart-fill', 'Tabela das conferências', 'tabela standings'],
    ['Jogos', 'schedule', 'calendar3', 'Resultados data por data', 'calendario calendário agenda placar resultados'],
    ['Playoffs', 'playoffs', 'trophy-fill', 'Chave e séries', 'chave mata-mata play-in playin'],
    ['Líderes', 'leaders', 'graph-up', 'Melhores médias da liga', 'estatisticas estatísticas stats'],
    ['Power ranking', 'power', 'lightning-charge-fill', 'Quem está mais forte', 'ranking forca força'],
    ['Times', 'teams', 'buildings-fill', 'Todas as franquias', 'franquias equipes'],
    ['História', 'history', 'clock-history', 'Campeões e temporadas passadas', 'historico histórico campeoes campeões'],
    ['Escalação', 'lineup', 'people-fill', 'Titulares e minutos', 'rotacao rotação quinteto titulares'],
    ['Diretoria e técnico', 'manage', 'briefcase-fill', 'Meta, técnico e esquemas', 'meu time diretoria tecnico técnico esquema'],
    ['Trocas', 'trades', 'arrow-left-right', 'Negocie com os outros times', 'troca trade'],
    ['Agentes livres', 'freeagency', 'person-plus-fill', 'Jogadores sem contrato', 'free agency contratar fa'],
    ['Folha e teto', 'cap', 'cash-coin', 'Salários e espaço no teto', 'cap salario salário folha teto contratos'],
    ['Drafts', 'draft', 'mortarboard-fill', 'Classes e escolhas', 'draft picks calouros'],
    ['Caixa de entrada', 'inbox', 'inbox-fill', 'Mensagens e decisões', 'mensagens inbox'],
];
$pageHits = [];
if ($q !== '') {
    foreach ($pages as $pg) {
        if (str_contains(mb_strtolower($pg[0] . ' ' . $pg[4]), $needle)) $pageHits[] = $pg;
    }
}
$qlink = fn(array $pg): string => '<a class="qlink" href="' . url($pg[1]) . '">' . bi($pg[2])
    . '<span><b>' . e($pg[0]) . '</b><small>' . e($pg[3]) . '</small></span></a>';
$total = count($players) + count($teams) + count($pageHits);

render_header('Busca');
page_head('Busca', [
    'eyebrow' => 'Liga',
    'sub' => 'Ache jogadores, times e telas do jogo pelo nome.',
]);
?>
<div class="stack">
  <form method="get" class="panel sr-form" role="search">
    <input type="hidden" name="p" value="search">
    <div class="sr-row">
      <label class="sr-field">
        <span class="sr-only">Buscar jogador, time ou tela</span>
        <?= bi('search') ?>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Jogador, time ou tela (ex.: Tatum, Lakers, trocas)" autocomplete="off"<?= $q === '' ? ' autofocus' : '' ?>>
      </label>
      <button class="btn btn-primary" type="submit"><?= bi('search') ?>Buscar</button>
    </div>
  </form>

  <?php if ($q === ''): ?>
    <p class="sr-count">Digite o nome de um jogador, de um time ou de uma tela. Alguns atalhos:</p>
    <div class="qlinks">
      <?php foreach ([$pages[0], $pages[3], $pages[5], $pages[1]] as $pg) echo $qlink($pg); ?>
    </div>

  <?php elseif ($total === 0): ?>
    <section class="panel">
      <?= empty_state('Nada encontrado para “' . $q . '”.', 'Confira a grafia ou tente só o sobrenome.', 'search') ?>
    </section>

  <?php else: ?>
    <p class="sr-count"><?= $total === 1 ? '1 resultado' : $total . ' resultados' ?> para “<?= e($q) ?>”</p>

    <?php if ($pageHits): ?>
    <section class="panel">
      <?= panel_head('Telas', ['icon' => 'grid-fill']) ?>
      <div class="qlinks">
        <?php foreach ($pageHits as $pg) echo $qlink($pg); ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($teams): ?>
    <section class="panel">
      <?= panel_head('Times', ['icon' => 'buildings-fill', 'meta' => count($teams) === 1 ? '1 time' : count($teams) . ' times']) ?>
      <div class="sr-teams">
        <?php foreach ($teams as $t): ?>
          <div class="sr-team<?= (int) $t['id'] === $gmId ? ' mine' : '' ?>">
            <?= team_who($t, ($t['conf'] === 'E' ? 'Leste' : 'Oeste') . ' · ' . (int) $t['wins'] . '-' . (int) $t['losses']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($players): ?>
    <section class="panel pad-0 sr-panel">
      <?= panel_head('Jogadores', ['icon' => 'person-fill', 'meta' => count($players) >= 40 ? 'Os 40 primeiros, por OVR' : (count($players) === 1 ? '1 jogador' : count($players) . ' jogadores')]) ?>
      <div class="table-wrap">
        <table class="tbl sr-tbl">
          <thead><tr>
            <th>Jogador</th>
            <th>Time</th>
            <th class="c">OVR</th>
            <th class="num hide-sm">Salário</th>
            <th class="num hide-sm">Pts/jogo</th>
          </tr></thead>
          <tbody>
          <?php foreach ($players as $p): $gp = (int) $p['gp']; ?>
            <tr<?= (int) $p['team_id'] === $gmId ? ' class="mine"' : '' ?>>
              <td><?= player_who($p, $p['pos'] . ' · ' . (int) $p['age'] . ' anos', (string) ($p['primary_color'] ?? '#1a1a2e')) ?></td>
              <td><a class="who" href="<?= url('team', ['id' => $p['team_id']]) ?>"><?= team_logo((string) $p['abbr'], (string) ($p['primary_color'] ?? '#333'), 'sm') ?><b><?= e($p['abbr']) ?></b></a></td>
              <td class="c"><?= ovr_badge($p['ovr'], 'sm') ?></td>
              <td class="num hide-sm"><?= money($p['salary'] ?? 0) ?></td>
              <td class="num hide-sm"><?= $gp ? avg($p['s_pts'], $gp) : '<span class="dim">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
